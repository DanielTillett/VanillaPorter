<?php
/**
 * All-purpose logic
 *
 * @copyright Vanilla Forums Inc. 2010
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 * @package VanillaPorter
 */
 
global $Supported;

/** @var array Supported forum packages: classname => array(name, prefix) */
$Supported = array(
   'vbulletin' => array('name'=>'vBulletin 3+', 'prefix'=>'vb_'),
   'vanilla' => array('name'=> 'Vanilla 1.x', 'prefix'=>'LUM_')
);

// Make sure a default time zone is set
if (ini_get('date.timezone') == '')
   date_default_timezone_set('America/Montreal');

/** 
 * Test filesystem permissions 
 */  
function TestWrite() {
   // Create file
   $file = 'vanilla2test.txt';
   @touch($file);
   if(is_writable($file)) {
      @unlink($file);
      return true;
   }
   else return false;
}

// Files
?>
<!-- Contents included from class.exportmodel.php -->
<?php
/**
 * @copyright Vanilla Forums Inc. 2010
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 * @package VanillaPorter
*/

/**
 * Object for exporting other database structures into a format that can be imported.
 */
class ExportModel {
	const COMMENT = '//';
	const DELIM = ',';
	const ESCAPE = '\\';
	const NEWLINE = "\n";
	const NULL = '\N';
	const QUOTE = '"';
	
	/**
	 * Create the export file and begin the export.
	 * @param string $Path The path to the export file.
	 * @param string $Source The source program that created the export. This may be used by the import routine to do additional processing.
	 */
	public function BeginExport($Path, $Source = '') {
      $this->Comments = array();
		$this->BeginTime = microtime(TRUE);
		$TimeStart = list($sm, $ss) = explode(' ', microtime());
		
		if($this->UseCompression && function_exists('gzopen'))
			$fp = gzopen($Path, 'wb');
		else
			$fp = fopen($Path, 'wb');
		$this->_File = $fp;
		
		fwrite($fp, 'Vanilla Export: '.$this->Version());
		if($Source)
			fwrite($fp, self::DELIM.' Source: '.$Source);
		fwrite($fp, self::NEWLINE.self::NEWLINE);
		$this->Comment('Export Started: '.date('Y-m-d H:i:s'));
	}

   public $Comments = array();
	
	/**
	 * Write a comment to the export file.
	 * @param string $Message The message to write.
	 * @param bool $Echo Whether or not to echo the message in addition to writing it to the file.
	 */
	public function Comment($Message, $Echo = TRUE) {
		fwrite($this->_File, self::COMMENT.' '.str_replace(self::NEWLINE, self::NEWLINE.self::COMMENT.' ', $Message).self::NEWLINE);
      if($Echo)
         $this->Comments[] = $Message;
	}
	
	/**
	 * End the export and close the export file. This method must be called if BeginExport() has been called or else the export file will not be closed.
	 */
	public function EndExport() {
		$this->EndTime = microtime(TRUE);
		$this->TotalTime = $this->EndTime - $this->BeginTime;
		
		$this->Comment('Export Completed: '.date('Y-m-d H:i:s'));
      $this->Comment(sprintf('Elapsed Time: %s', self::FormatElapsed($this->TotalTime)));
		
		if($this->UseCompression && function_exists('gzopen'))
			gzclose($this->_File);
		else
			fclose($this->_File);
	}

   static function FormatElapsed($Start, $End = NULL) {
      if($End === NULL)
         $Elapsed = $Start;
      else
         $Elapsed = $End - $Start;

      $m = floor($Elapsed / 60);
		$s = $Elapsed - $m * 60;
      $Result = sprintf('%02d:%05.2f', $m, $s);

      return $Result;
   }
	
	/** @var object File pointer */
	protected $_File = NULL;
	
	/** @var object PDO instance */
	protected $_PDO = NULL;
	
	/**
	 * Gets or sets the PDO connection to the database.
	 * @param mixed $DsnOrPDO One of the following:
	 *  - <b>String</b>: The dsn to the database.
	 *  - <b>PDO</b>: An existing connection to the database.
	 *  - <b>Null</b>: The PDO connection will not be set.
	 *  @param string $Username The username for the database if a dsn is specified.
	 *  @param string $Password The password for the database if a dsn is specified.
	 *  @return PDO The current database connection.
	 */
	public function PDO($DsnOrPDO = NULL, $Username = NULL, $Password = NULL) {
		if (!is_null($DsnOrPDO)) {
			if($DsnOrPDO instanceof PDO)
				$this->_PDO = $DsnOrPDO;
			else {
				$this->_PDO = new PDO($DsnOrPDO, $Username, $Password);
				if(strncasecmp($DsnOrPDO, 'mysql', 5) == 0)
					$this->_PDO->exec('set names utf8');
			}
		}
		return $this->_PDO;
	}
	
	public function Query($Query) {
	  $Query = str_replace(':_', $this->Prefix, $Query); // replace prefix.
	  $Result = $this->PDO()->query($Query, PDO::FETCH_ASSOC);
	  return $Result;
	}
	
	/**
	 * Export a table to the export file.
	 * @param string $TableName the name of the table to export. This must correspond to one of the accepted vanilla tables.
	 * @param mixed $Query The query that will fetch the data for the export this can be one of the following:
	 *  - <b>String</b>: Represents a string of sql to execute.
	 *  - <b>PDOStatement</b>: Represents an already executed query resultset.
	 *  - <b>Array</b>: Represents an array of associative arrays or objects containing the data in the export.
	 *  @param array $Mappings Specifies mappings, if any, between the source and the export where the keys represent the source columns and the values represent Vanilla columns.
	 *	  - If you specify a Vanilla column then it must be in the export structure contained in this class.
	 *   - If you specify a MySQL type then the column will be added.
	 *   - If you specify an array you can have the following keys: Column, and Type where Column represents the new column name and Type represents the MySQL type.
	 *  For a list of the export tables and columns see $this->Structure().
	 */
	public function ExportTable($TableName, $Query, $Mappings = array()) {
		$BeginTime = microtime(TRUE);
      $fp = $this->_File;
		
		// Make sure the table is valid for export.
		if(!array_key_exists($TableName, $this->_Structures)) {
			$this->Comment("Error: $TableName is not a valid export."
				." The valid tables for export are ". implode(", ", array_keys($this->_Structures)));
			fwrite($fp, self::NEWLINE);
			return;
		}
		$Structure = $this->_Structures[$TableName];
		
		// Start with the table name.
		fwrite($fp, 'Table: '.$TableName.self::NEWLINE);
		
		// Get the data for the query.
		if(is_string($Query)) {
			$Data = $this->Query($Query);
		} elseif($Query instanceof PDOStatement) {
			$Data = $Query;
		}
		
		// print_r($this->PDO()->errorInfo());
		
		// Set the search and replace to escape strings.
		$EscapeSearch = array(self::ESCAPE, self::DELIM, self::NEWLINE, self::QUOTE); // escape must go first
		$EscapeReplace = array(self::ESCAPE.self::ESCAPE, self::ESCAPE.self::DELIM, self::ESCAPE.self::NEWLINE, self::ESCAPE.self::QUOTE);
		
		// Loop through the data and write it to the file.
      $RowCount = 0;
		while ($Data && $Data->rowCount() && $Row = $Data->fetch(PDO::FETCH_ASSOC)) {
			$Row = (array)$Row; // export%202010-05-06%20210937.txt
         $RowCount++;
			if($RowCount == 1) {
				// Get the export structure.
				$ExportStructure = $this->GetExportStructure($Row, $Structure, $Mappings);

				// Build and write the table header.
				$TableHeader = $this->_GetTableHeader($ExportStructure, $Structure);

				fwrite($fp, $TableHeader.self::NEWLINE);

				$Mappings = array_flip($Mappings);
			}

			$First = TRUE;
			
			// Loop through the columns in the export structure and grab their values from the row.
			$ExRow = array();
			foreach($ExportStructure as $Field => $Type) {
				// Get the value of the export.
				if(array_key_exists($Field, $Row)) {
					// The column has an exact match in the export.
					$Value = $Row[$Field];
				} elseif(array_key_exists($Field, $Mappings)) {
					// The column is mapped.
					$Value = $Row[$Mappings[$Field]];
				} else {
					$Value = NULL;
				}
				// Format the value for writing.
				if(is_null($Value)) {
					$Value = self::NULL;
				} elseif(is_numeric($Value)) {
					// Do nothing, formats as is.
				} elseif(is_string($Value)) {
					//if(mb_detect_encoding($Value) != 'UTF-8')
					//   $Value = utf8_encode($Value);
					
					$Value = self::QUOTE
						.str_replace($EscapeSearch, $EscapeReplace, $Value)
						.self::QUOTE;
				} elseif(is_bool($Value)) {
					$Value = $Value ? 1 : 0;
				} else {
					// Unknown format.
					$Value = self::NULL;
				}
				
				$ExRow[] = $Value;
			}
			// Write the data.
			fwrite($fp, implode(self::DELIM, $ExRow));
			// End the record.
			fwrite($fp, self::NEWLINE);
		}
		
		// Write an empty line to signify the end of the table.
		if(!$FirstRow)
			fwrite($fp, self::NEWLINE);
		
		if($Data instanceof PDOStatement)
			$Data->closeCursor();

      $EndTime = microtime(TRUE);
      $Elapsed = self::FormatElapsed($BeginTime, $EndTime);
      $this->Comment("Exported table: $TableName ($RowCount rows, $Elapsed)");
	}

	public function GetExportStructure($Row, $Structure, &$Mappings) {
		$ExportStructure = array();
		// See what columns from the structure are in

		// See what columns to add to the end of the structure.
		foreach($Row as $Column => $X) {
			if(array_key_exists($Column, $Mappings)) {
				$Mapping = $Mappings[$Column];
				if(is_string($Mapping)) {
					if(array_key_exists($Mapping, $Structure)) {
						// This an existing column.
						$DestColumn = $Mapping;
						$DestType = $Structure[$DestColumn];
					} else {
						// This is a created column.
						$DestColumn = $Column;
						$DestType = $Mapping;
					}
				} elseif(is_array($Mapping)) {
					$DestColumn = $Mapping['Column'];
					$DestType = $Mapping['Type'];
					$Mappings[$Column] = $DestColumn;
				}
			} elseif(array_key_exists($Column, $Structure)) {
				$DestColumn = $Column;
				$DestType = $Structure[$Column];
			} else {
				$DestColumn = '';
				$DestType = '';
			}

			// Check to see if we have to add the column to the export structure.
			if($DestColumn && !array_key_exists($DestColumn, $ExportStructure)) {
				// TODO: Make sure $DestType is a valid MySQL type.
				$ExportStructure[$DestColumn] = $DestType;
			}
		}
		return $ExportStructure;
	}

	protected function _GetTableHeader($Structure, $GlobalStructure) {
		$TableHeader = '';

		foreach($Structure as $Column => $Type) {
			if(strlen($TableHeader) > 0)
				$TableHeader .= self::DELIM;
			if(array_key_exists($Column, $GlobalStructure)) {
				$TableHeader .= $Column;
			} else {
				$TableHeader .= $Column.':'.$Type;
			}
		}
		return $TableHeader;
	}
	
	/**
	 * @var string The database prefix. When you pass a sql string to ExportTable() it will replace occurances of :_ with this property.
	 * @see vnExport::ExportTable()
	 */
	public $Prefix = '';
	
	/**
	 * @var array Destination table structure
	 */
	protected $_Structures = array(
		'Activity' => array(
         'ActivityUserID' => 'int', 
         'RegardingUserID' => 'int', 
         'Story' => 'text', 
         'InsertUserID' => 'int', 
         'DateInserted' => 'datetime'),
		'Category' => array(
         'CategoryID' => 'int', 
         'Name' => 'varchar(30)', 
         'Description' => 'varchar(250)', 
         'ParentCategoryID' => 'int', 
         'DateInserted' => 'datetime', 
         'InsertUserID' => 'int', 
         'DateUpdated' => 'datetime', 
         'UpdateUserID' => 'int'),
		'Comment' => array(
         'CommentID' => 'int', 
         'DiscussionID' => 'int', 
         'DateInserted' => 'datetime', 
         'InsertUserID' => 'int', 
         'DateUpdated' => 'datetime', 
         'UpdateUserID' => 'int', 
         'Format' => 'varchar(20)', 
         'Body' => 'text', 
         'Score' => 'float'),
      'Conversation' => array(
         'ConversationID' => 'int', 
         'FirstMessageID' => 'int', 
         'DateInserted' => 'datetime', 
         'InsertUserID' => 'int', 
         'DateUpdated' => 'datetime', 
         'UpdateUserID' => 'int'),
		'ConversationMessage' => array(
         'MessageID' => 'int', 
         'ConversationID' => 'int', 
         'Body' => 'text', 
         'InsertUserID' => 'int', 
         'DateInserted' => 'datetime'),
		'Discussion' => array(
         'DiscussionID' => 'int', 
         'Name' => 'varchar(100)',
			'Body' => 'text',
         'CategoryID' => 'int', 
         'DateInserted' => 'datetime', 
         'InsertUserID' => 'int', 
         'DateUpdated' => 'datetime', 
         'UpdateUserID' => 'int', 
         'Score' => 'float', 
         'Closed' => 'tinyint', 
         'Announce' => 'tinyint'),
		'Role' => array(
         'RoleID' => 'int', 
         'Name' => 'varchar(100)', 
         'Description' => 'varchar(200)',
			'CanSession' => 'tinyint'),
		'User' => array(
         'UserID' => 'int', 
         'Name' => 'varchar(20)', 
         'Email' => 'varchar(200)', 
         'Password' => 'varbinary(34)', 
         //'Gender' => array('m', 'f'), 
         'Score' => 'float',
         'InviteUserID' => 'int',
         'HourOffset' => 'int',
			'CountDiscussions' => 'int',
         'CountComments' => 'int',
			'PhotoPath' => 'varchar(255)',
         'DateOfBirth' => 'datetime',
         'DateFirstVisit' => 'datetime',
         'DateLastActive' => 'datetime',
         'DateInserted' => 'datetime',
         'DateUpdated' => 'datetime'),
      'UserConversation' => array(
         'UserID' => 'int', 
         'ConversationID' => 'int', 
         'LastMessageID' => 'int'),
      'UserDiscussion' => array(
         'UserID' => 'int', 
         'DiscussionID' => 'int',
			'Bookmarked' => 'tinyint',
			'DateLastViewed' => 'datetime',
			'CountComments' => 'int'),
      'UserMeta' => array(
         //'UMetaKey' => 'int', 
         'UserID' => 'int', 
         'MetaKey' => 'varchar(255)',
         'MetaValue' => 'text'),
		'UserRole' => array(
         'UserID' => 'int', 
         'RoleID' => 'int')
	);
	
	/**
	 * Returns an array of all the expected export tables and expected columns in the exports.
	 * When exporting tables using ExportTable() all of the columns in this structure will always be exported in the order here, regardless of how their order in the query.
	 * @return array
	 * @see vnExport::ExportTable()
	 */
	public function Structures() {
		return $this->_Structures;
	}
	
	/**
	 * @var bool Whether or not to use compression when creating the file.
	 */
	public $UseCompression = TRUE;
	
	/**
	 * Returns the version of export file that will be created with this export.
	 * The version is used when importing to determine the format of this file.
	 * @return string
	 */
	public function Version() {
		return '1.0';
	}
	
	/**
	 * Checks all required source tables are present
	 */
	public function VerifySource($RequiredTables) {
      $MissingTables = false;
      $CountMissingTables = 0;
      $MissingColumns = array();
      
      foreach($RequiredTables as $ReqTable => $ReqColumns) {
         $TableDescriptions = $this->Query('describe :_'.$ReqTable);
         //echo 'describe '.$Prefix.$ReqTable;
         if($TableDescriptions === false) { // Table doesn't exist
            $CountMissingTables++;
            if($MissingTables !== false)
               $MissingTables .= ', '.$ReqTable;
            else
               $MissingTables = $ReqTable;
         }
         else {
            // Build array of columns in this table
            $PresentColumns = array();
            foreach($TableDescriptions as $TD) {
                $PresentColumns[] = $TD['Field'];
            }
            // Compare with required columns
            foreach($ReqColumns as $ReqCol) {
               if(!in_array($ReqCol, $PresentColumns))
                  $MissingColumns[$ReqTable][] = $ReqCol;
            }
            
         }
      }
      
      // Return results
      if($MissingTables===false) {
         if(count($MissingColumns) > 0) {          
         }
         else return true; // Nothing missing!
      }
      elseif($CountMissingTables == count($RequiredTables)) {
         return 'Required tables not present. Check Database Name and Table Prefix and try again.';
      }
      else {
         return 'Missing required database tables: '.$MissingTables;
      }
   }
}
?><?php

?>
<!-- Contents included from views.php -->
<?php
/**
 * Views for Vanilla 2 export tools
 *
 * @copyright Vanilla Forums Inc. 2010
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 * @package VanillaPorter
 */
 
   
/**
 * HTML header
 */
function PageHeader() {
   ?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html>
<head>
   <title>Vanilla 2 Forum Export Tool</title>
   <!-- Contents included from ./design/style.css -->
<style>
body {
	font-family: 'lucida grande','Lucida Sans Unicode', tahoma, sans-serif;
   /* TODO: Mark, link this to a vanilaforums.com url */
   background: url('slicesplash.jpg') top center no-repeat #C7E6FB;
   margin: 0px;
   padding: 0px;
   text-align: center;
   color:#076C8E;
   text-shadow:0 1px 0 #FFFFFF;   
   }
a,
a:link,
a:active,
a:visited {
   color: #2786C2;
   text-decoration: none;
   }
a:hover {
   color: #FF0084 !important;
   text-decoration: underline;
   }
div.Title {
   background:#E2F4FF none repeat scroll 0 0;
   border-top: 1px solid #A5D0E7;
   border-bottom: 1px solid #A5D0E7;
	margin: 50px 0;
	padding: 30px 0 4px;
}
div.Title h1 {
	text-align: left;
	width: 600px;
	margin: 0 auto;
}
div.Title img {
	top: 20px;
	position: absolute;
}
div.Title p {
	padding: 0 0 0 270px;
	margin: 0;
	font-size: 30px;
}

h1 {
   font-family: Arial, Helvetica, Verdana;
   color: #02455B;
   width: 568px;
   margin: 0 auto;
   padding: 0;
   font-size: 180%;
}
div.Form {
   text-align: center;
}
div.Form ul {
   width: 500px;
   margin: 0 auto;
	padding: 0;
}

div.Errors {
   background: #d00;
   padding: 20px 8px !important;
   margin: 0 0 10px;
   border-bottom: 1px solid #C0E7F5;
}
.Errors li {
   padding: 4px 0 !important;
   border: 0px !important;
   margin: 0px !important;
   color: #fff !important;
   font-size: 16px;
   line-height: 150%;
   text-shadow: #900 0 1px 0;
}
.Errors li pre,
.Errors li code {
	-moz-border-radius: 3px;
	-webkit-border-radius: 3px;
	border: 1px solid #b00;
	background: #c00;
	margin: 10px 0 0;
	padding: 4px 8px;
	display: block;
	text-shadow: none;
	font-size: 13px;
	font-weight: normal;
	font-family: monospace;
}
.Errors li a {
   color: #ffff00;
	text-decoration: underline;
}
.Errors li a:hover {
   color: #ff0 !important;
	text-decoration: none;
}
.ImportProgress {
   padding-left: 40px;
   /* TODO: Mark, link this to a vanilaforums.com url */
   background: url('progress.gif') left center no-repeat;
}
.ImportProgress strong {
   background: #ff9;
   color: #000;
   padding: 3px 6px;
   margin: 0 4px;
   -moz-border-radius: 2px;
   -webkit-border-radius: 2px;
}
.Loading {
   height: 100px;
   /* TODO: Mark, link this to a vanilaforums.com url */
   background: url('progress.gif') center center no-repeat;
}
.Progress {
   padding: 10px 40px 10px 0px;
   /* TODO: Mark, link this to a vanilaforums.com url */
   background: url('progress.gif') center center no-repeat;
}
.Hidden {
   display: none;
}
/* Forms */
form {
   margin: 0 0 20px;
   text-align: right;
}
form ul {
   text-align: left;
   list-style: none;
   margin: 0px;
   padding: 10px;
}
form ul li {
   padding: 10px 0;
   font-size: 18px;
}
form ul li.Warning {
   padding-bottom: 0;
   border-bottom: 0;
   font-size: 17px;
}
form ul li.Warning div {
   font-size: 14px;
	line-height: 1.6;
	color: #000;
	text-shadow: none;
   padding: 16px 0 8px;
}
form ul li label {
   font-family: Arial, Helvetica, Verdana;
   font-weight: bold;
   display: block;
   padding: 8px 0 0;
   font-size: 110%;
	color: #02455B;
   }
form ul li label span {
	font-size: 13px;
	color: #555;
	font-weight: normal;
	text-shadow: none;
	padding: 0 0 0 10px;
}
form ul li select {
   -moz-border-radius: 4px;
   -webkit-border-radius: 4px;
   font-size: 110%;
   padding: 8px;
   width: 496px;
   border: 1px solid #ccc;
   color: #555;
}
form ul li input.InputBox {
   -moz-border-radius: 4px;
   -webkit-border-radius: 4px;
   font-size: 110%;
   padding: 8px;
   width: 480px;
   border: 1px solid #ccc;
   color: #555;
}
form ul li input.InputBox:focus {
   color: #000;
   background: #FFFEDE;
   border: 1px solid #aaa;
}
form ul li.Last {
   padding: 12px 0 2px;
   border-bottom: 0;
}
div.Button {
   text-align: right;
   padding: 12px 0 30px;
   width: 496px;
   margin: 0 auto;
}
div.Button a,
input.Button {
   cursor: pointer;
   font-family: arial, helvetica, verdana;
   font-size: 25px;
   font-weight: bold;
   color: #02475A;
	text-shadow: 0 1px 0 #fff;
   margin: 0;
   padding: 3px 10px;
   /* TODO: Mark, link this to a vanilaforums.com url */
   background: url('buttonbg.png') repeat-x center left #f8f8f8;
   border: 1px solid #999;
   -moz-border-radius: 3px;
   -webkit-border-radius: 3px;
	box-shadow: 0px 0px 2px #999;
	-moz-box-shadow: 0px 0px 2px #999;
	-webkit-box-shadow: 0px 0px 2px #999;  
}
div.Button a {
   padding: 4px 8px;
}
div.Button a:hover,
input.Button:hover {
   text-decoration: none;
   color: #111;
   border: 1px solid #666;
}
div.Button a:focus,
input.Button:focus {
   background: #eee;
}
/* readme.html */
div.Info {
	text-align: left;
	width: 568px;
	margin: 0 auto 70px;
	font-size: 80%;
	line-height: 1.6;
}
div.Info h1 {
	padding: 6px 0 0;
	margin: 0;
}
div.Info p {
	color: #000;
	padding: 3px 0 6px;
	margin: 0;
	text-shadow: none;
}
div.Info li {
	color: #000;
	padding: 1px 0;
	margin: 0;
	text-shadow: none;
}

</style>
</head>
<body>
<div id="Frame">
	<div id="Content">
      <div class="Title">
         <h1>
            <!-- TODO: Mark, link this to an external vanillaforums.com image -->
            <img src="./design/vanilla_logo.png" alt="Vanilla" />
            <p>Forum Export Tool</p>
         </h1>
      </div>
   <?php
}

   
/**
 * HTML footer
 */
function PageFooter() {
   ?>
   </div>
</div>
</body>
</html><?php

}

   
/**
 * Message: Write permission fail
 */
function ViewNoPermission($msg) {
   PageHeader(); ?>
   <div class="Messages Errors">
      <ul>
         <li><?php echo $msg; ?></li>
      </ul>
   </div>
   
   <?php PageFooter();
}

   
/**
 * Form: Database connection info
 */
function ViewForm($forums, $msg='', $Info = '') {
   PageHeader(); ?>
   <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="post">
      <input type="hidden" name="step" value="info" />
      <div class="Form">
         <?php if($msg!='') : ?>
         <div class="Messages Errors">
            <ul>
               <li><?php echo $msg; ?></li>
            </ul>
         </div>
         <?php endif; ?>
         <ul>
            <li>
               <label>Source Forum Type</label>
               <select name="type">
               <?php foreach($forums as $forumClass => $forumInfo) : ?>
                  <option value="<?php echo $forumClass; ?>"><?php echo $forumInfo['name']; ?></option>
               <?php endforeach; ?>
               </select>
            </li>
            <li>
               <label>Table Prefix <span>Table prefix is not required</span></label>
               <input class="InputBox" type="text" name="prefix" value="<?php echo urlencode(GetValue('prefix')) ?>" />
            </li>
            <li>
               <label>Database Host <span>Database host is usually "localhost"</span></label>
               <input class="InputBox" type="text" name="dbhost" value="<?php echo urlencode(GetValue('dbhost', '', 'localhost')) ?>" />
            </li>
            <li>
               <label>Database Name</label>
               <input class="InputBox" type="text" name="dbname" value="<?php echo urlencode(GetValue('dbname')) ?>" />
            </li>
            <li>
               <label>Database Username</label>
               <input class="InputBox" type="text" name="dbuser" value="<?php echo urlencode(GetValue('dbuser')) ?>" />
            </li>
            <li>
               <label>Database Password</label>
               <input class="InputBox" type="password" name="dbpass" />
            </li>
         </ul>
         <div class="Button">
            <input class="Button" type="submit" value="Begin Export" />
         </div>
      </div>
   </form>
   <script type="text/javascript">
   //<![CDATA[
      function updatePrefix() {
         var type = document.getElementById('forumType').value;
         switch(type) {
            <?php foreach($forums as $forumClass => $forumInfo) : ?>
            case '<?php echo $forumClass; ?>': document.getElementById('forumPrefix').value = '<?php echo $forumInfo['prefix']; ?>'; break;
            <?php endforeach; ?>
         }
      }
   //]]>
   </script> 

   <?php PageFooter();
}


/**
 * Message: Result of export
 */
function ViewExportResult($Msgs = '', $Class = 'Info') {
   PageHeader();
   if($Msgs) {
      // TODO: Style this a bit better.
      echo "<div class=\"$Class\">";
      foreach($Msgs as $Msg) {
         echo "<p>$Msg</p>\n";
      }
      echo "</div>";
   }
   PageFooter();
}

function GetValue($Key, $Collection = NULL, $Default = '') {
   if(!$Collection)
      $Collection = $_POST;
   if(array_key_exists($Key, $Collection))
      return $Collection[$Key];
   return $Default;
}
?><?php

?>
<!-- Contents included from class.exportcontroller.php -->
<?php
/**
 * @copyright Vanilla Forums Inc. 2010
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 * @package VanillaPorter
 */

/** Generic controller implemented by forum-specific ones */
abstract class ExportController {
   
   /** @var array Database connection info */
   protected $DbInfo = array();
   
   /** @var array Required tables, columns set per exporter */
   protected $SourceTables = array();

   /** Forum-specific export routine */
   abstract protected function ForumExport($Ex);
   
   /** 
    * Construct and set the controller's properties from the posted form.
    */
   public function __construct() {
      $this->HandleInfoForm();
   }
   
   /** 
    * Logic for export process 
    */
   public function DoExport() {
      global $Supported;
      
      // Test connection
      $Msg = $this->TestDatabase();
      if($Msg === true) {
         // Create db object
         $Ex = new ExportModel;
         $Dsn = 'mysql:dbname='.$this->DbInfo['dbname'].';host='.$this->DbInfo['dbhost'];
         $Ex->PDO($Dsn, $this->DbInfo['dbuser'], $this->DbInfo['dbpass']);
         $Ex->Prefix = $this->DbInfo['prefix'];
         // Test src tables' existence structure
         $Msg = $Ex->VerifySource($this->SourceTables);
         if($Msg === true) {
            // Good src tables - Start dump
            $Ex->UseCompression = TRUE;
            set_time_limit(60*2);
            $this->ForumExport($Ex);

            // Write the results.
            ViewExportResult($Ex->Comments);
         }
         else 
            ViewForm($Supported, $Msg, $this->DbInfo); // Back to form with error
      }
      else 
         ViewForm($Supported, $Msg, $this->DbInfo); // Back to form with error
   }
   
   /** 
    * User submitted db connection info 
    */
   public function HandleInfoForm() {
      $this->DbInfo = array(
         'dbhost' => $_POST['dbhost'],
         'dbuser' => $_POST['dbuser'], 
         'dbpass' => $_POST['dbpass'], 
         'dbname' => $_POST['dbname'],
         'type'   => $_POST['type'],
         'prefix' => preg_replace('/[^A-Za-z0-9_-]/','',$_POST['prefix']));
   }
   
   /** 
    * Test database connection info
    */
   public function TestDatabase() {
      // Connection
      if($C = mysql_connect($this->DbInfo['dbhost'], $this->DbInfo['dbuser'], '')) { // $this->DbInfo['dbpass'])) {
         // Database
         if(mysql_select_db($this->DbInfo['dbname'], $C)) { 
            mysql_close($C);
            return true;
         }
         else {
            mysql_close($C);
            return 'Could not find database &ldquo;'.$this->DbInfo['dbname'].'&rdquo;.';
         }
      }
      else 
         return 'Could not connect to '.$this->DbInfo['dbhost'].' as '.$this->DbInfo['dbuser'].' with given password.';
   }
}
?><?php


?>
<!-- Contents included from class.vanilla.php -->
<?php

class Vanilla extends ExportController {

   /** @var array Required tables => columns for vBulletin import */  
   protected $_SourceTables = array(
      'user'=> array()
      );
   
   /**
    * Forum-specific export format
    * @todo Project file size / export time and possibly break into multiple files
    * 
    */
   protected function ForumExport($Ex) {
      // Begin
      $Ex->BeginExport('export '.date('Y-m-d His').'.txt.gz', 'Vanilla 1.x');   
      
      // Users
      $User_Map = array(
         'UserID'=>'UserID',
         'Name'=>'Name',
         'Password'=>'Password',
         'Email'=>'Email',
         'CountComments'=>'CountComments'
      );   
      $Ex->ExportTable('User', "SELECT * FROM :_User", $User_Map  );  // ":_" will be replaced by database prefix
      
      // Roles
      /*
		    'RoleID' => 'int', 
		    'Name' => 'varchar(100)', 
		    'Description' => 'varchar(200)'
		 */
      $Role_Map = array(
         'RoleID'=>'RoleID',
         'Name'=>'Name',
         'Description'=>'Description'
      );   
      $Ex->ExportTable('Role', 'select * from :_Role', $Role_Map);
  

      // UserRoles
      /*
		    'UserID' => 'int', 
		    'RoleID' => 'int'
		 */
      $UserRole_Map = array(
         'UserID' => 'UserID', 
         'RoleID'=> 'RoleID'
      );
      $Ex->ExportTable('UserRole', 'select UserID, RoleID from :_User', $UserRole_Map);
      
      // Categories
      /*
          'CategoryID' => 'int', 
          'Name' => 'varchar(30)', 
          'Description' => 'varchar(250)', 
          'ParentCategoryID' => 'int', 
          'DateInserted' => 'datetime', 
          'InsertUserID' => 'int', 
          'DateUpdated' => 'datetime', 
          'UpdateUserID' => 'int'
		 */
      $Category_Map = array(
         'CategoryID' => 'CategoryID', 
         'Name' => 'Name',
         'Description'=> 'Description'
      );
      $Ex->ExportTable('Category', "select CategoryID, Name, Description from :_Category", $Category_Map);

      
      // Discussions
      /*
		    'DiscussionID' => 'int', 
		    'Name' => 'varchar(100)', 
		    'CategoryID' => 'int', 
		    'Body' => 'text', 
		    'Format' => 'varchar(20)', 
		    'DateInserted' => 'datetime', 
		    'InsertUserID' => 'int', 
		    'DateUpdated' => 'datetime', 
		    'UpdateUserID' => 'int', 
		    'Score' => 'float', 
		    'Announce' => 'tinyint', 
		    'Closed' => 'tinyint'
		 */
      $Discussion_Map = array(
         'DiscussionID' => 'DiscussionID', 
         'Name' => 'Name',
         'CategoryID'=> 'CategoryID', 
         'Body'=> 'Body',
         'DateCreated'=>'DateInserted',
         'AuthUserID'=>'InsertUserID',
         'DateLastActive'=>'DateUpdated',
         'LastUserID'=>'UpdateUserID',
         'Closed'=>'Closed',
      );
      $Ex->ExportTable('Discussion', "
         SELECT d.*,c.Body FROM :_Discussion d
         LEFT JOIN :_Comment c ON (c.CommentID = d.FirstCommentID)", $Discussion_Map);
      
      // Comments
      /*
		    'CommentID' => 'int', 
		    'DiscussionID' => 'int', 
		    'DateInserted' => 'datetime', 
		    'InsertUserID' => 'int', 
		    'DateUpdated' => 'datetime', 
		    'UpdateUserID' => 'int', 
		    'Format' => 'varchar(20)', 
		    'Body' => 'text', 
		    'Score' => 'float'
		 */
      $Comment_Map = array(
         'CommentID' => 'CommentID',
         'DiscussionID' => 'DiscussionID',
         'AuthUserID' => 'InsertUserID',
         'DateCreated' => 'DateInserted',
         'EditUserID' => 'UpdateUserID',
         'DateEdited' => 'DateUpdated',
         'Body' => 'Body'
      );
      $Ex->ExportTable('Comment', "
         SELECT * FROM :_Comment c
         WHERE c.WhisperUserID = 0", $Comment_Map);
      
      // Conversations
      /*
          'ConversationID' => 'int', 
          'FirstMessageID' => 'int', 
          'DateInserted' => 'datetime', 
          'InsertUserID' => 'int', 
          'DateUpdated' => 'datetime', 
          'UpdateUserID' => 'int'
      */
      $Conversation_Map = array(
         'DiscussionID' => 'ConversationID',
         'AuthUserID' => 'InsertUserID',
         'DateCreated' => 'DateInserted',
         'EditUserID' => 'UpdateUserID',
         'DateEdited' => 'DateUpdated'
      );
      $Ex->ExportTable('Conversation', "SELECT DISTINCT DiscussionID, AuthUserID, DateCreated, EditUserID, DateEdited 
         FROM :_Comment c
         WHERE c.WhisperUserID > 0
         GROUP BY DiscussionID", $Conversation_Map);
      
      // ConversationMessage
      /*
         'MessageID' => 'int', 
         'ConversationID' => 'int', 
         'Body' => 'text', 
         'InsertUserID' => 'int', 
         'DateInserted' => 'datetime'
      */
      $ConversationMessage_Map = array(
         'CommentID' => 'MessageID',
         'DiscussionID' => 'ConversationID',
         'Body' => 'Body',
         'AuthUserID' => 'InsertUserID',
         'DateCreated' => 'DateInserted'
      );
      $Ex->ExportTable('ConversationMessage', "
         SELECT CommentID, DiscussionID, AuthUserID, DateCreated, Body FROM :_Comment c
         WHERE c.WhisperUserID > 0", $ConversationMessage_Map);
      
      // UserConversation
      $Ex->Query("CREATE TEMPORARY TABLE VanillaExportUserConversations (`UserID` INT NOT NULL ,`ConversationID` INT NOT NULL)");
      $Ex->Query("
            INSERT INTO VanillaExportUserConversations (ConversationID, UserID) 
            SELECT DISTINCT DiscussionID AS ConversationID, AuthUserID AS UserID FROM :_Comment 
            WHERE WhisperUserID > 0
            GROUP BY DiscussionID");
      $Ex->Query("
            INSERT INTO VanillaExportUserConversations (ConversationID, UserID) 
            SELECT DISTINCT DiscussionID AS ConversationID, WhisperUserID AS UserID FROM :_Comment
            WHERE WhisperUserID > 0
            GROUP BY DiscussionID");
      /*
         'UserID' => 'int', 
         'ConversationID' => 'int', 
         'LastMessageID' => 'int'
      */
      $UserConversation_Map = array(
         'UserID' => 'UserID',
         'ConversationID' => 'ConversationID'
      );
      $Ex->ExportTable('UserConversation', "SELECT ConversationID, UserID FROM VanillaExportUserConversations", $UserConversation_Map);
         
      // End
      $Ex->EndExport();
   }

}
?>
<?php

?>
<!-- Contents included from class.vbulletin.php -->
<?php
/**
 * vBulletin-specific exporter tool
 *
 * @copyright Vanilla Forums Inc. 2010
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 * @package VanillaPorter
 * @todo importer: html_entity_decode Category names and Discussion titles
 * @todo importer: count bookmarks, bookmark comment count
 * @todo importer: update Discussions with first & last comment ids
 * @todo importer: update CountDiscussions column on the Category, User tables
 * @todo importer: don't make ALL discussions "new" after import
 */
 
class Vbulletin extends ExportController {
   
   /** @var array Required tables => columns for vBulletin import */
   protected $SourceTables = array(
      'user' => array('userid','username','password','email','referrerid','timezoneoffset','posts','salt',
         'birthday_search','joindate','lastvisit','lastactivity','membergroupids','usergroupid',
         'usertitle', 'homepage', 'aim', 'icq', 'yahoo', 'msn', 'skype', 'styleid'),
      'usergroup'=> array('usergroupid','title','description'),
      'userfield' => array('userid'),
      'phrase' => array('varname','text','product','fieldname','varname'),
      'thread' => array('threadid','forumid','postuserid','title','open','sticky','dateline','lastpost'),
      'deletionlog' => array('type','primaryid'),
      'post' => array('postid','threadid','pagetext','userid','dateline'),
      'forum' => array('forumid','description','displayorder','title','description','displayorder'),
      'subscribethread' => array('userid','threadid')
   );
   
   /**
    * Forum-specific export format
    * @todo Project file size / export time and possibly break into multiple files
    */
   protected function ForumExport($Ex) {
      // Begin
      $Ex->BeginExport('export '.date('Y-m-d His').'.txt'.($Ex->UseCompression ? '.gz' : ''), 'vBulletin 3+');
      
      // Users
      $User_Map = array(
         'userid'=>'UserID',
         'username'=>'Name',
         'password2'=>'Password',
         'email'=>'Email',
         'referrerid'=>'InviteUserID',
         'timezoneoffset'=>'HourOffset',
         //'posts'=>'CountComments',
         'salt'=>'char(3)'
      );
      $Ex->ExportTable('User', "select *,
				concat(`password`, salt) as password2,
            DATE_FORMAT(birthday_search,GET_FORMAT(DATE,'ISO')) as DateOfBirth,
            FROM_UNIXTIME(joindate) as DateFirstVisit,
            FROM_UNIXTIME(lastvisit) as DateLastActive,
            FROM_UNIXTIME(joindate) as DateInserted,
            FROM_UNIXTIME(lastactivity) as DateUpdated
         from :_user", $User_Map);  // ":_" will be replace by database prefix
      
      
      // Roles
      $Role_Map = array(
         'usergroupid'=>'RoleID',
         'title'=>'Name',
         'description'=>'Description'
      );   
      $Ex->ExportTable('Role', 'select * from :_usergroup', $Role_Map);
  
  
      // UserRoles
      $UserRole_Map = array(
         'userid'=>'UserID',
         'usergroupid'=>'RoleID'
      );
      $Ex->Query("CREATE TEMPORARY TABLE VbulletinRoles (userid INT UNSIGNED NOT NULL, usergroupid INT UNSIGNED NOT NULL)");
      # Put primary groups into tmp table
      $Ex->Query("insert into VbulletinRoles (userid, usergroupid) select userid, usergroupid from :_user");
      # Put stupid CSV column into tmp table
      $SecondaryRoles = $Ex->Query("select userid, membergroupids from :_user");
      foreach($SecondaryRoles as $Row) {
         if($Row['membergroupids']!='') {
            $Groups = explode(',',$Row['membergroupids']);
            foreach($Groups as $GroupID) {                  
               $Ex->Query("insert into VbulletinRoles (userid, usergroupid) values(".$Row['userid'].",".$GroupID."");
            }
         }
      }
      # Export from our tmp table and drop
      $Ex->ExportTable('UserRole', 'select userid, usergroupid from VbulletinRoles', $UserRole_Map);
      $Ex->Query("DROP TABLE VbulletinRoles");

      
      // UserMeta
      $Ex->Query("CREATE TEMPORARY TABLE VbulletinUserMeta (`UserID` INT NOT NULL ,`MetaKey` VARCHAR( 64 ) NOT NULL ,`MetaValue` VARCHAR( 255 ) NOT NULL)");
      # Standard vB user data
      $UserFields = array('usertitle', 'homepage', 'aim', 'icq', 'yahoo', 'msn', 'skype', 'styleid');
      foreach($UserFields as $Field)
         $Ex->Query("insert into VbulletinUserMeta (UserID, MetaKey, MetaValue) select userid, '".$Field."', ".$Field." from :_user where ".$Field."!=''");
      # Dynamic vB user data (userfield)
      $ProfileFields = $Ex->Query("select varname, text from :_phrase where product='vbulletin' and fieldname='cprofilefield' and varname like 'field%_title'");
      foreach ($ProfileFields as $Field) {
         $VbulletinField = str_replace('_title','',$Field['varname']);
         $MetaKey = preg_replace('/[^0-9a-z_-]/','',strtolower($Field['text']));
         $Ex->Query("insert into VbulletinUserMeta (UserID, MetaKey, MetaValue) 
            select userid, '".$MetaKey."', ".$VbulletinField." from :_userfield where ".$VbulletinField."!=''");
      }
      # Export from our tmp table and drop
      $Ex->ExportTable('UserMeta', 'select UserID, MetaKey, MetaValue from VbulletinUserMeta');
      $Ex->Query("DROP TABLE VbulletinUserMeta");

      
      // Categories
      $Category_Map = array(
         'forumid'=>'CategoryID',
         'description'=>'Description',
         'displayorder'=>array('Column'=>'Sort', 'Type'=>'int')
      );
      $Ex->ExportTable('Category', "select forumid, left(title,30) as Name, description, displayorder
         from :_forum where threadcount > 0", $Category_Map);

      
      // Discussions
      $Discussion_Map = array(
         'threadid'=>'DiscussionID',
         'forumid'=>'CategoryID',
         'postuserid'=>'InsertUserID',
         'postuserid'=>'UpdateUserID',
         'title'=>'Name',
			'Format'=>'Format'
      );
      $Ex->ExportTable('Discussion', "select t.*,
				p.pagetext as Body,
				'BBCode' as Format,
            replycount+1 as CountComments, 
            convert(ABS(open-1),char(1)) as Closed, 
            convert(sticky,char(1)) as Announce,
            FROM_UNIXTIME(t.dateline) as DateInserted,
            FROM_UNIXTIME(lastpost) as DateUpdated,
            FROM_UNIXTIME(lastpost) as DateLastComment
         from :_thread t
            left join :_deletionlog d ON (d.type='thread' AND d.primaryid=t.threadid)
				left join :_post p ON p.postid = t.firstpostid
         where d.primaryid IS NULL", $Discussion_Map);
      
      // Comments
      $Comment_Map = array(
         'postid' => 'CommentID',
         'threadid' => 'DiscussionID',
         'pagetext' => 'Body',
			'Format' => 'Format'
      );
      $Ex->ExportTable('Comment', "select p.*,
				'BBCode' as Format,
            p.userid as InsertUserID,
            p.userid as UpdateUserID,
            FROM_UNIXTIME(p.dateline) as DateInserted,
            FROM_UNIXTIME(p.dateline) as DateUpdated
         from :_post p
				inner join :_thread t ON p.threadid = t.threadid
            left join :_deletionlog d ON (d.type='post' AND d.primaryid=p.postid)
         where p.postid <> t.firstpostid and d.primaryid IS NULL", $Comment_Map);
      
      // UserDiscussion
		$UserDiscussion_Map = array(
			'DateLastViewed' =>  'datetime');
      $Ex->ExportTable('UserDiscussion', "select
           tr.userid as UserID,
           tr.threadid as DiscussionID,
           FROM_UNIXTIME(tr.readtime) as DateLastViewed,
           case when st.threadid is not null then 1 else 0 end as Bookmarked
         from nb_threadread tr
         left join nb_subscribethread st on tr.userid = st.userid and tr.threadid = st.threadid");
      
      // Activity (3.8+)
      $Activity_Map = array(
         'postuserid'=>'ActivityUserID',
         'userid'=>'RegardingUserID',
         'pagetext'=>'Story',
         'postuserid'=>'InsertUserID'
      );
		$Tables = $Ex->Query("show tables like ':_visitormessage'");
      if (count($Tables) > 0) { # Table is present
			$Ex->ExportTable('Activity', "select *, 
			   FROM_UNIXTIME(dateline) as DateInserted
			from :_visitormessage
			where state='visible'", $Activity_Map);
      }
      
      // End
      $Ex->EndExport();
   }
   
}
?><?php


// Logic
if(isset($_POST['type']) && array_key_exists($_POST['type'], $Supported)) {
   // Mini-Factory
   $class = ucwords($_POST['type']);
   $Controller = new $class();
   $Controller->DoExport();
}
else {
   // View form or error
   if(TestWrite())
      ViewForm($Supported);
   else
      ViewNoPermission("This script has detected that it does not have permission to create files in the current directory. Please rectify this and retry.");
}
?>