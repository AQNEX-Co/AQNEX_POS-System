<?php
$files = glob('c:/xampp/htdocs/tech/settings/*.php');
foreach($files as $file) {
    if (basename($file) == 'index.php') continue; // Already updated manually
    $content = file_get_contents($file);
    // Find the heavy <style> block by matching :root and --ink-900
    if (strpos($content, '--ink-900') !== false && strpos($content, '<style>') !== false) {
        $new_content = preg_replace('/<style>\s*.*?<\/style>/s', '<link rel="stylesheet" href="<?php echo $prefix; ?>assets/css/settings.css">', $content, 1);
        if($content !== $new_content) {
            file_put_contents($file, $new_content);
            echo "Updated $file\n";
        }
    }
}
?>
