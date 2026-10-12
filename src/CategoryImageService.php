<?php
declare(strict_types=1);

/** Shared category artwork generated once, independently of recipe persistence. */
final class CategoryImageService {
    public function __construct(private PDO $pdo,private RecipePhotoService $photos,private string $directory,private string $url) {}
    public function ensure(string $id,string $userId,string $householdId): string {
        (new HouseholdService($this->pdo))->membership($userId, $householdId);
        $q=$this->pdo->prepare("SELECT c.label,c.slug,p.label AS parent_label FROM recipe_categories c JOIN recipe_categories p ON p.id=c.parent_id WHERE c.id=? AND EXISTS (SELECT 1 FROM recipe_category_assignments a WHERE a.category_id=c.id AND a.household_id=?)");
        $q->execute([$id,$householdId]); $category=$q->fetch(PDO::FETCH_ASSOC);
        if (!$category || !str_starts_with($category['slug'],'import-')) throw new HttpError(404,'category_not_found','New category not found.');
        if (!is_dir($this->directory) && !mkdir($this->directory,0755,true)) throw new RuntimeException('Cannot create category image directory.');
        $filename='category-'.$id.'.jpg'; $path=$this->directory.'/'.$filename;
        $lock=fopen($path.'.lock','c+');
        if (!$lock) throw new RuntimeException('Cannot lock category image.');
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); throw new HttpError(409,'photo_pending','Category image is being generated.'); }
        try {
            if (!is_file($path)) {
                (new HouseholdService($this->pdo))->requireAiImages($userId, $householdId);
                $photo=$this->photos->generateCategory($category['label'],$category['parent_label'],$userId)['photo'];
                $bytes=base64_decode($photo['image_base64'],true);
                $temporary=$path.'.tmp';
                if (file_put_contents($temporary,$bytes,LOCK_EX)!==strlen($bytes)) throw new RuntimeException('Cannot save category image.');
                chmod($temporary,0644);
                if (!rename($temporary,$path)) throw new RuntimeException('Cannot publish category image.');
            }
            $this->pdo->prepare('UPDATE recipe_categories SET image_key=? WHERE id=?')->execute(['ai-'.$id,$id]);
            return rtrim($this->url,'/').'/'.$filename;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
