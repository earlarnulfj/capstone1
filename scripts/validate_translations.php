<?php
// Validate translation completeness: ensure all languages have keys present in English
$configPath = __DIR__ . '/../config/translations.json';
if (!file_exists($configPath)) {
  fwrite(STDERR, "translations.json not found at $configPath\n");
  exit(2);
}
$json = file_get_contents($configPath);
$cfg = json_decode($json, true);
if (!$cfg || !isset($cfg['translations']['en'])) {
  fwrite(STDERR, "Invalid translations.json: missing English base\n");
  exit(2);
}
$base = $cfg['translations']['en'];
$baseKeys = array_keys($base);
$langs = isset($cfg['languages']) && is_array($cfg['languages']) ? $cfg['languages'] : array_keys($cfg['translations']);
$errors = [];
foreach ($langs as $lang) {
  if ($lang === 'en') continue;
  $dict = isset($cfg['translations'][$lang]) ? $cfg['translations'][$lang] : [];
  foreach ($baseKeys as $k) {
    if (!array_key_exists($k, $dict)) {
      $errors[] = "Missing key '$k' in language '$lang'";
    }
  }
}
if ($errors) {
  foreach ($errors as $e) fwrite(STDERR, $e . "\n");
  exit(1);
}
echo "Translations complete: all languages have required keys\n";
exit(0);
?>