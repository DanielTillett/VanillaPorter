<html>
   <title>Make</title>
</html>
<body>
   <pre><?php
   /** Make the final vanilla2export.php file from the other sources.
    */

   // Open the file.
   $Path = dirname(__FILE__).'/vanilla2export.php';
   echo "Opening $Path\n";
   $fp = fopen($Path, 'w');

   AddFile($fp, 'index.php');

   fclose($fp);
   echo "Make Complete.\n";

   ?></pre>
</body>
<?php
/// Functions ///
function AddFile($fp, $Filename) {
   $Contents = GetFile($Filename);
   fwrite($fp, $Contents);
}

function GetFile($Filename, $EndPhp = FALSE) {
   $Path = dirname(__FILE__).'/'.$Filename;
   echo "Including file $Path\n";

   $Contents = file_get_contents($Path);

   // Inline any stylesheet includes.
   $Contents = preg_replace_callback('/<link.*?href=[\'"](.*?)[\'"].*?\/>/i', 'ReplaceStyleCallback', $Contents);

   // Inline any includes.
   $Contents = preg_replace_callback('/include\([\'"](.*?)[\'"]\);/', 'ReplaceIncludeCallback', $Contents);

   // End and begin the php context.
   if($EndPhp) {
      $Contents = "?>\n<!-- Contents included from $Filename -->\n".$Contents."<?php\n";
   }
   
   return $Contents;
}

function ReplaceIncludeCallback($Matches) {
   $Path = $Matches[1];
   $Contents = GetFile($Path, TRUE);
   $Result = $Contents;

   return $Result;
}

function ReplaceStyleCallback($Matches) {
   $Path = $Matches[1];
   $Contents = file_get_contents(dirname(__FILE__).'/'.$Path);
   $Result = "<!-- Contents included from $Path -->\n<style>\n".$Contents."\n</style>";

   return $Result;
}
