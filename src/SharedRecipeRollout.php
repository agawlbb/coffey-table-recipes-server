<?php
declare(strict_types=1);

/** Fail-closed deployment primitives. Never restores over the live database. */
final class SharedRecipeRollout {
    public const MIGRATIONS=['011_shared_recipes.sql','012_canonical_relationships.sql'];
    public function __construct(private PDO $pdo, private string $directory, private string $migrations) {}
    private function quote(string $name): string {
        if (!preg_match('/^[A-Za-z0-9_]+$/D',$name)) throw new RuntimeException('Unexpected SQL identifier.');
        return '`'.$name.'`';
    }
    public function inventory(?array $baseline=null): array {
        $result=[];
        $tables=$baseline===null ? $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) : array_keys($baseline);
        sort($tables);
        foreach($tables as $table) {
            $q=$this->quote($table);
            $columns=$baseline[$table]['columns']??array_column(array_filter($this->pdo->query("SHOW COLUMNS FROM $q")->fetchAll(PDO::FETCH_ASSOC),static fn($r)=>!str_contains($r['Extra'],'GENERATED')),'Field');
            $columnSql=implode(',',array_map(fn($name)=>$this->quote($name),$columns));
            $primary=$this->pdo->query("SHOW INDEX FROM $q WHERE Key_name='PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
            usort($primary,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
            $order=array_column($primary,'Column_name');
            if (!$order) throw new RuntimeException('Backup requires primary keys on every table.');
            $sql="SELECT $columnSql FROM $q ORDER BY ".implode(',',array_map(fn($name)=>$this->quote($name),$order));
            $hash=hash_init('sha256');$count=0;
            foreach($this->pdo->query($sql,PDO::FETCH_NUM) as $row) {
                // PDO values are canonicalized so native numeric types do not affect hashes.
                hash_update($hash,json_encode(array_map(static fn($v)=>$v===null?null:(string)$v,$row),JSON_THROW_ON_ERROR)."\n");$count++;
            }
            $result[$table]=['columns'=>$columns,'rows'=>$count,'sha256'=>hash_final($hash)];
        }
        return $result;
    }
    public function inspect(): array {
        $engine=(string)$this->pdo->query('SELECT VERSION()')->fetchColumn();
        // Exact engine versions rehearsed by this tool. Expand only after rehearsal.
        if (!preg_match('/^(?:10\.4\.32|10\.11\.19)-MariaDB(?:[-+].*)?$/D',$engine)) throw new RuntimeException('Hosted engine needs a matching disposable rehearsal before this tool can apply migrations.');
        foreach($this->pdo->query('SHOW TABLE STATUS',PDO::FETCH_ASSOC) as $table) if($table['Engine']!=='InnoDB') throw new RuntimeException('Only InnoDB base tables are supported.');
        if($this->pdo->query('SHOW TRIGGERS')->fetch()) throw new RuntimeException('Triggers require an expanded backup plan.');
        foreach(['ROUTINES','EVENTS'] as $kind) if((int)$this->pdo->query("SELECT COUNT(*) FROM information_schema.$kind WHERE ".($kind==='ROUTINES'?'ROUTINE_SCHEMA':'EVENT_SCHEMA').'=DATABASE()')->fetchColumn()) throw new RuntimeException('Stored routines/events require an expanded backup plan.');
        $ledger=$this->pdo->query('SELECT version,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach(glob($this->migrations.'/*.sql') as $file) {
            if((int)basename($file)>10) continue;
            $name=basename($file);
            if(!isset($ledger[$name]) || !migrationChecksumMatches($name,$ledger[$name],hash_file('sha256',$file))) throw new RuntimeException('Earlier migration ledger mismatch.');
        }
        foreach(self::MIGRATIONS as $name) if(isset($ledger[$name]) && $ledger[$name]!==hash_file('sha256',$this->migrations.'/'.$name)) throw new RuntimeException('Migration checksum mismatch.');
        return ['engine'=>$engine,'ledger'=>$ledger];
    }
    public function backup(): array {
        $inspection=$this->inspect();
        if(is_file($this->directory.'/database.jsonl')) throw new RuntimeException('Backup already exists; retain it and inspect the rollout state.');
        $out=fopen($this->directory.'/database.jsonl','x');if(!$out)throw new RuntimeException('Cannot create backup.');chmod($this->directory.'/database.jsonl',0600);
        $write=static function(string $sql)use($out):void{$line=json_encode($sql,JSON_THROW_ON_ERROR)."\n";if(fwrite($out,$line)!==strlen($line))throw new RuntimeException('Backup write failed.');};
        try {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $inventory=$this->inventory();
            $preservationSpec=$inventory;unset($preservationSpec['schema_migrations']);
            // Migration backfill legitimately updates the automatic recipe timestamp.
            $preservationSpec['recipes']['columns']=array_values(array_diff($preservationSpec['recipes']['columns'],['updated_at']));
            $preservation=$this->inventory($preservationSpec);
            $write('SET NAMES utf8mb4');$write('SET FOREIGN_KEY_CHECKS=0');
            foreach($inventory as $table=>$meta) {
                $q=$this->quote($table);$write($this->pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM)[1]);
                $columns=implode(',',array_map(fn($name)=>$this->quote($name),$meta['columns']));
                foreach($this->pdo->query("SELECT $columns FROM $q",PDO::FETCH_NUM) as $row) $write("INSERT INTO $q ($columns) VALUES (".implode(',',array_map(fn($v)=>$v===null?'NULL':$this->pdo->quote((string)$v),$row)).')');
            }
            $write('SET FOREIGN_KEY_CHECKS=1');if(!fflush($out))throw new RuntimeException('Backup flush failed.');$this->pdo->commit();
        } finally {if($this->pdo->inTransaction())$this->pdo->rollBack();fclose($out);}
        return $inspection+['inventory'=>$inventory,'preservation'=>$preservation,'backup_sha256'=>hash_file('sha256',$this->directory.'/database.jsonl')];
    }
    public function restoreDrill(PDO $recovery,array $backup): void {
        if($recovery->query('SELECT DATABASE()')->fetchColumn()===$this->pdo->query('SELECT DATABASE()')->fetchColumn()) throw new RuntimeException('Recovery database must differ from live.');
        if($recovery->query('SHOW TABLES')->fetch())throw new RuntimeException('Recovery database must be empty.');
        $file=$this->directory.'/database.jsonl';
        if(!hash_equals($backup['backup_sha256'],hash_file('sha256',$file)))throw new RuntimeException('Backup checksum mismatch.');
        $stream=fopen($file,'r');
        try {while(($line=fgets($stream))!==false)$recovery->exec(json_decode($line,true,512,JSON_THROW_ON_ERROR));}
        finally{fclose($stream);$recovery->exec('SET FOREIGN_KEY_CHECKS=1');}
        $copy=new self($recovery,$this->directory,$this->migrations);
        if($copy->inventory()!==$backup['inventory'])throw new RuntimeException('Restored backup inventory mismatch.');
    }
    public function apply(array $backup,?callable $afterStatement=null): void {
        $this->inspect();
        if($this->inventory()!==$backup['inventory'])throw new RuntimeException('Database changed after backup.');
        if(!hash_equals($backup['backup_sha256'],hash_file('sha256',$this->directory.'/database.jsonl')))throw new RuntimeException('Backup checksum mismatch.');
        $journal=$this->directory.'/ddl-started.json';
        if(file_exists($journal))throw new RuntimeException('Prior DDL attempt: do not retry automatically. Keep maintenance and reconcile schema/ledger.');
        if(!isset($backup['restore_verified']) || $backup['restore_verified']!==true)throw new RuntimeException('A successful restore drill is required.');
        $ledger=$this->pdo->query('SELECT version,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
        // Unexpected partial schema with no recorded migration must never be rerun.
        if(!isset($ledger[self::MIGRATIONS[0]])) {
            if($this->pdo->query("SHOW TABLES LIKE 'recipe_households'")->fetch() || $this->pdo->query("SHOW COLUMNS FROM recipes LIKE 'trash_household_id'")->fetch())throw new RuntimeException('Unrecorded partial migration 011.');
        }
        if(!isset($ledger[self::MIGRATIONS[1]]) && $this->pdo->query("SHOW TABLES LIKE 'recipe_relationship_lock'")->fetch())throw new RuntimeException('Unrecorded partial migration 012.');
        $handle=fopen($journal,'x');if(!$handle)throw new RuntimeException('Cannot create DDL journal.');
        $entry=json_encode(['started_at'=>gmdate('c'),'backup_sha256'=>$backup['backup_sha256']],JSON_THROW_ON_ERROR);
        try{if(fwrite($handle,$entry)!==strlen($entry) || !fflush($handle))throw new RuntimeException('Cannot persist DDL journal.');}finally{fclose($handle);}chmod($journal,0600);
        foreach(self::MIGRATIONS as $name) {
            if(isset($ledger[$name]))continue;
            foreach(explode(';',file_get_contents($this->migrations.'/'.$name)) as $index=>$sql) {
                if(trim($sql)==='')continue;$this->pdo->exec($sql);
                if($afterStatement!==null)$afterStatement($name,$index);
            }
            $this->pdo->prepare('INSERT INTO schema_migrations(version,checksum) VALUES (?,?)')->execute([$name,hash_file('sha256',$this->migrations.'/'.$name)]);
        }
        $this->verify($backup);
    }
    public function verify(array $backup): void {
        $info=$this->inspect();foreach(self::MIGRATIONS as $name)if(!isset($info['ledger'][$name]))throw new RuntimeException('Migration not recorded.');
        $original=$backup['preservation'];
        if($this->inventory($original)!==$original)throw new RuntimeException('Original database content changed.');
        $missing=(int)$this->pdo->query('SELECT COUNT(*) FROM recipes r WHERE NOT EXISTS (SELECT 1 FROM recipe_households h WHERE h.recipe_id=r.id AND h.household_id=r.household_id AND h.removed_at <=> r.deleted_at)')->fetchColumn();
        if($missing || (int)$this->pdo->query('SELECT COUNT(*) FROM recipes WHERE deleted_at IS NOT NULL AND NOT(trash_household_id <=> household_id)')->fetchColumn())throw new RuntimeException('Attachment/trash backfill mismatch.');
        if((int)$this->pdo->query('SELECT COUNT(*) FROM recipe_relationship_lock WHERE id=1')->fetchColumn()!==1)throw new RuntimeException('Relationship lock missing.');
        foreach(['recipe_editor_user'=>'updated_by_user_id','recipe_canonical_parent'=>'parent_recipe_id','pairing_canonical_recipe'=>'recipe_id','pairing_canonical_partner'=>'paired_recipe_id'] as $name=>$column){
            $q=$this->pdo->prepare('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_NAME=?');$q->execute([$name]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==1 || $rows[0]['COLUMN_NAME']!==$column || $rows[0]['REFERENCED_COLUMN_NAME']!=='id' || $rows[0]['REFERENCED_TABLE_NAME']!==($name==='recipe_editor_user'?'users':'recipes'))throw new RuntimeException('Canonical foreign key mismatch.');
        }
    }
}
