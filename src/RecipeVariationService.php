<?php
declare(strict_types=1);

/** Creates cached review drafts only; the ordinary editor is the sole save path. */
final class RecipeVariationService
{
    public function __construct(private array $config, private string $storage, private $transport = null) {}

    public static function accessPolicy(array $config): string {
        $policy = $config['variation_access'] ?? 'all_members';
        if (!in_array($policy, ['disabled', 'ai_images', 'all_members'], true)) $policy = 'disabled';
        if ($policy === 'disabled') throw new HttpError(503, 'variation_unavailable', 'Recipe variations are not enabled yet.');
        // Household membership is enforced by the endpoint, independently of billing.
        return $policy;
    }

    public function analyze(array $source, array $body, string $userId, string $householdId, array $categories = []): array {
        self::accessPolicy($this->config);
        $changes = $body['changes'] ?? null;
        $requestId = $body['request_id'] ?? null;
        $answers = $body['answers'] ?? [];
        if (!is_string($changes) || trim($changes) === '' || mb_strlen($changes) > 4000
            || !is_string($requestId) || !preg_match('/^[a-f0-9]{32}$/D', $requestId)
            || !is_array($answers) || array_values($answers) !== $answers || count($answers) > 24) {
            throw new HttpError(422, 'invalid_variation', 'Describe the changes in at most 4,000 characters.');
        }
        foreach ($answers as $answer) {
            if (!is_array($answer) || !is_string($answer['question'] ?? null) || !is_string($answer['answer'] ?? null)
                || mb_strlen($answer['question']) > 1000 || mb_strlen($answer['answer']) > 2000) {
                throw new HttpError(422, 'invalid_variation', 'Use shorter clarification answers.');
            }
        }
        $parent = (string)$source['id'];
        $identity = hash('sha256', json_encode([$userId,$householdId,$parent,$requestId], JSON_THROW_ON_ERROR));
        // Only cooking content reaches the provider: no household/member IDs, media or related recipes.
        $original = array_intersect_key($source, array_flip(['title','description','original_language','measurement_system','yield_text','servings','prep_minutes','cook_minutes','total_minutes','notes']));
        foreach (['ingredients'=>['original_text','ingredient_name','quantity_text','quantity_decimal','quantity_numerator','quantity_denominator','quantity_max_decimal','original_unit','unit_code','preparation','is_optional','is_main_ingredient','scale_quantity'], 'steps'=>['instruction','section_title']] as $list=>$fields) {
            $original[$list] = array_map(static fn(array $row): array => array_intersect_key($row,array_flip($fields)), $source[$list] ?? []);
        }
        // Preserve cooking meaning carried only in group labels or structured
        // actions while keeping database identifiers out of provider input.
        $groupTitles = [];
        foreach ($source['groups'] ?? [] as $group) $groupTitles[(string)$group['id']] = $group['title'];
        foreach ($source['ingredients'] ?? [] as $index=>$ingredient) {
            $title = $groupTitles[(string)($ingredient['group_id'] ?? '')] ?? null;
            if ($title !== null) $original['ingredients'][$index]['group_title'] = $title;
        }
        foreach ($source['steps'] ?? [] as $index=>$step) {
            $original['steps'][$index]['actions'] = [];
            foreach ($source['actions'] ?? [] as $action) {
                if ((string)($action['step_id'] ?? '') !== (string)$step['id']) continue;
                $original['steps'][$index]['actions'][] = array_intersect_key($action,array_flip([
                    'action_type','label','duration_seconds','temperature_value','temperature_unit','appliance',
                ]));
            }
        }
        $input = ['original_recipe'=>$original,'requested_changes'=>trim($changes),'clarification_answers'=>$answers];
        $text = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($text)>60000) throw new HttpError(422,'invalid_variation','This recipe is too long to vary automatically.');
        $fingerprint = hash('sha256', json_encode(['complete-variation-with-english-explanation-v2',$text,$this->config['variation_model'] ?? $this->config['model'],$categories], JSON_THROW_ON_ERROR));
        $directory = $this->storage.'/variation-drafts';
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Cannot prepare variation draft storage.');
        $path = $directory.'/'.$identity.'.json';
        $file = fopen($path, 'c+');
        if ($file === false) throw new RuntimeException('Cannot open variation draft storage.');
        try {
            if (!flock($file,LOCK_EX)) throw new RuntimeException('Cannot lock variation draft.');
            $stored = json_decode(stream_get_contents($file),true) ?? [];
            $base = hash('sha256', trim($changes));
            if (isset($stored['changes']) && !hash_equals($stored['changes'],$base)) throw new HttpError(409,'variation_changed','Start a new variation after changing the request.');
            if (isset($stored['drafts'][$fingerprint])) return $stored['drafts'][$fingerprint];
            if (count($stored['drafts'] ?? []) >= 8) throw new HttpError(429,'variation_limit','This variation has reached its clarification limit. Start a new request.');
            $config = $this->config;
            $config['model'] = $config['variation_model'] ?? $config['model'];
            $draft = (new RecipeImportService($config,$this->storage,$this->transport))->analyze(['kind'=>'text','text'=>$text],$userId,$categories,true);
            $draft['recipe']['parent_recipe_id'] = $parent;
            $draft['recipe']['is_variation'] = true;
            $draft['recipe']['source_type'] = 'ai_variation';
            $draft['recipe']['source_title'] = 'Variation of '.$source['title'];
            $draft['recipe']['source_text'] = 'Requested variation: '.trim($changes);
            $draft['recipe']['source_url'] = null;
            $draft['recipe']['import_key'] = $identity;
            $draft['recipe']['tags'] = [['kind'=>'category','label'=>'Variation']];
            $draft['recipe']['photos'] = [];
            $draft['recipe']['nutrition'] = null;
            $draft['preprocessing'] = 'AI recipe variation';
            $stored['changes'] = $base;
            $stored['drafts'][$fingerprint] = $draft;
            $encoded = json_encode($stored,JSON_THROW_ON_ERROR);
            rewind($file); ftruncate($file,0);
            if (fwrite($file,$encoded) !== strlen($encoded)) throw new RuntimeException('Cannot save variation draft.');
            fflush($file); chmod($path,0600);
            return $draft;
        } finally { flock($file,LOCK_UN); fclose($file); }
    }
}
