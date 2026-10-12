<?php
declare(strict_types=1);

final class RecipeActionCleanup {
    public const REMOVE = "NOT (COALESCE((action_type IN ('timer','rest') AND duration_seconds > 0),0) OR (action_type = 'appliance_temperature' AND temperature_value IS NOT NULL AND temperature_unit IN ('C','F')))";

    public static function run(PDO $pdo, string $storage, bool $apply): array {
        if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== configuration()['database']['name']) throw new RuntimeException('Hosted recipe database required.');
        $pdo->beginTransaction();
        try {
            // Lock recipes first, matching the normal editor's lock ordering.
            $recipes=$pdo->query('SELECT id,household_id,revision,updated_at FROM recipes ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            $rows=$pdo->query("SELECT * FROM recipe_step_actions WHERE ".self::REMOVE." OR action_type='rest' ORDER BY recipe_id,id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            $removed=[]; $converted=[]; $ids=[];
            foreach ($rows as $row) {
                if ($row['action_type']==='rest' && (int)$row['duration_seconds']>0) $converted[]=$row;
                else $removed[]=$row;
                $ids[(string)$row['recipe_id']]=true;
            }
            $report=['remove'=>count($removed),'convert_timed_rest_to_timer'=>count($converted),'recipes'=>count($ids)];
            if (!$apply || !$rows) { $pdo->rollBack(); return $report; }
            $backup=['created_at'=>gmdate('c'),'database'=>$pdo->query('SELECT DATABASE()')->fetchColumn(),'actions'=>$rows,'recipes'=>array_values(array_filter($recipes,fn($r)=>isset($ids[(string)$r['id']]))),'report'=>$report];
            $path=rtrim($storage,'/').'/action-cleanup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
            $bytes=json_encode($backup,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
            if (file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)) throw new RuntimeException('Backup failed.');
            chmod($path,0600);
            if (hash_file('sha256',$path)!==hash('sha256',$bytes)) throw new RuntimeException('Backup verification failed.');
            $pdo->exec('DELETE FROM recipe_step_actions WHERE '.self::REMOVE);
            $pdo->exec("UPDATE recipe_step_actions SET action_type='timer' WHERE action_type='rest' AND duration_seconds>0");
            $update=$pdo->prepare('UPDATE recipes SET revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE id=?');
            $nutrition=$pdo->prepare('UPDATE recipe_nutrition SET recipe_revision=recipe_revision+1 WHERE recipe_id=? AND recipe_revision=?');
            foreach ($recipes as $recipe) if (isset($ids[(string)$recipe['id']])) {
                // Action cleanup does not alter ingredients or nutrition freshness.
                $nutrition->execute([$recipe['id'],$recipe['revision']]);
                $update->execute([$recipe['id']]);
            }
            $remaining=(int)$pdo->query('SELECT COUNT(*) FROM recipe_step_actions WHERE '.self::REMOVE." OR action_type='rest'")->fetchColumn();
            if ($remaining!==0) throw new RuntimeException('Cleanup verification failed.');
            $pdo->commit();
            return $report+['remaining_unwanted'=>0,'backup'=>basename($path),'backup_sha256'=>hash('sha256',$bytes)];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
}
