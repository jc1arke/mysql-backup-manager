<?php
/**
 * MySQL Backup Manager
 * PHP based MySQL Backup Manager script used to backup the databases specified in the database controlling the script
 * @author JA Clarke
 * @since 2010/03/23
 *
 * @version 0.1
 * @description 
 * - Initial Framework configuration
 * - Command line option getting and filtering
 * @todo
 * - Implement DB getting
 *
 * @version 0.2
 * @description
 * - Added Project Spook implementation
 *
 * @version 0.3
 * @description
 * - Added MD5 file CRC's for compressed DB backup files
 * - Added proper commenting to the functions and classes as well as core internal code segments
 * - Added the time limit line so that it will not timeout due to a DB backup operation taking to long
 * - Fixed a couple of simulated/non-simulated run options
 *
 */

/**
 * Set the time limit to zero (0) so that the script cannot time out due to a long backup process
 */
set_time_limit(0);

/**
 * Setup the core defines
 */
define(	"LIVE", 	true				);
define(	"DEBUG", 	false				);
define(	"APPID",	"309401bf"	);

$system_version = "0.3";

// Load the config file 
if( LIVE === true ) :
	require_once "/ts_systems/config/config.php";
	include "/ts_systems/systemreporter/agent/system.reporter.agent.php";
else :
	require_once "/www/bill/systems/config/config.php";
	include "/www/bill/systems/systemreporter/agent/system.reporter.agent.php";
endif;

/**
 * Class MySQLHandler
 */
class MySQLHandler
{
	/**
	 * Public Function runBackup
	 * @param db string The name of the database that the backup is going to be executed for
	 * @param simulation boolean Whether or not the run is a simulated run or actual run
	 * @param color boolean Whether or not output should be generated in color code or not
	 */
	public function runBackup( $db, $simulation = false, $color = false )
	{
		// Check if the system is live or not
		if( LIVE === true ) :
			$backupDir = "/ts_backups/mysql_backups/";
		else :
			$backupDir = "/srv_admin/srv_backups/mysql/";
		endif;
		
		// Full backups are run on Friday's, setup the commands accordingly
		if( date("D") == "Fri" ) :
			$backupDir 		.= "full_backups/";
			$mysql_options	= "";
		else :
			$backupDir .= "incremental_backups/";
			$mysql_options	= "--flush-logs --delete-master-logs";
		endif;
		
		$backupDir .= date("d_m_Y") . "/";
		
		// Check if the backup directory exists, else create it
		if( !is_dir( $backupDir ) ) :
			mkdir($backupDir);
		endif;
		
		// Setup the backup command
		$command = "mysqldump -u " . MySQLBackupDB::USER . " -p" . MySQLBackupDB::PASS . " -h " . MySQLBackupDB::DBHOST . " --port=" . MySQLBackupDB::DBPORT . " " . (DEBUG?"--verbose":"") . " {$db} {$mysql_options} | gzip -m9 > {$backupDir}{$db}.sql.gz";
		if( DEBUG === true ) : OutputHandler::displayOutput( "[%lightgreen%DEBUG%lightgray%] MySQL Command : %lightblue%{$command}%lightgray%", $color ); endif;
		
		// Check if it is a simulated run
		if( !$simulation ) :
			exec($command, $command_output);
		else :
			$command_output = array( "Backup complete" );
		endif;
		
		// Output the results from the command that was executed
		foreach( $command_output as $cmd_output ) :
			OutputHandler::displayOutput( "[%lightblue%{$db}%lightgray%]\t" . $cmd_output, $color );
		endforeach;
	}
}

/**
 * Class MySQL_Backup_System
 */
class MySQL_Backup_System
{
	/**
	 * Private class members
	 */
	private $debug_data		= null;
	private $current_db		= null;
	private $simulation		= null;
	private $color				= null;
	
	/**
	 * Overrridden constructor
	 * @param sim boolean Enable/disable simulation mode
	 * @param clr boolean Enable/disable color output of content
	 */
	public function __construct( $sim = false, $clr = false )
	{
		$this -> simulation	= $sim;
		$this -> color 		= $clr;
		OutputHandler::displayOutput("%lightblue%Starting system...%lightgray%", $this -> color);
		SR_Agent::Log( APPID, SystemReporter::MSG_MESSAGE, "Starting System" );
	}
	
	/**
	 * Public function runBackups
	 */
	public function runBackups()
	{
		$mysqlDBHandler = new MySQLHandler();
		//$dbList = DB::getDBList($this -> color);
		$dbList = explode( "|", MySQLBackupDB::DATABASES );
		foreach( $dbList as $db ) :
			SR_Agent::Log( APPID, SystemReporter::MSG_MESSAGE, "Starting Backup for {$db}" );
			OutputHandler::displayOutput( "[%lightblue%{$db}%lightgray%]\tStarting backup\n%white%============================================%lightgray%", $this -> color );
			$mysqlDBHandler -> runBackup( $db, $this -> simulation, $this -> color );
			OutputHandler::displayOutput( "%white%============================================%lightgray%\n", $this -> color );
		endforeach;
		
		$this -> completeRun();
	}

	/**
	 * Private Fucntion completeRun
	 * @description
	 * Function is called after all the databases have been backed up, in order to create a final MD5 checksum of the databases and then compress it all as a single file
	 */	
	private function completeRun()
	{
		// Check if it is the LIVE environment
		if( LIVE === true ) :
			$backupDir = "/ts_backups/mysql_backups/";
		else :
			$backupDir = "/srv_admin/srv_backups/mysql/";
		endif;
		
		// Check if it was a full or incremental backup run
		if( date("D") == "Fri" ) :
			$backupDir 		.= "full_backups/";
		else :
			$backupDir .= "incremental_backups/";
		endif;
		
		// Setup paths and filenames
		$folder = $backupDir;
		$backupDir .= date("d_m_Y") . "/";
		$compressed_file = $folder  . date("d_m_Y") . ".tar.gz";
		
		// Check if it is a simulated run
		if( !$this -> simulation ) :
			exec("md5sum {$backupDir}/*.tar.gz > {$backupDir}/databases.md5");
			if( DEBUG === true ) : OutputHandler::displayOutput( "[%lightgreen%DEBUG%lightgray%] Generated MD5 Hashes of DB Backups%lightgray%", $this -> color ); endif;
		endif;
		
		$command = "tar -zcvf {$compressed_file} {$backupDir}";
		if( DEBUG === true ) : OutputHandler::displayOutput( "[%lightgreen%DEBUG%lightgray%] Command : %lightblue%{$command}%lightgray%", $this -> color ); endif;
		
		// Check if it is a simulated run
		if( !$this -> simulation ) :
			exec($command, $command_output);
			// Generate MD5 checksum of the compressed file (usefull for integrity checks)
			exec("md5sum {$compressed_file} > {$compressed_file}.md5");
		else :
			$command_output = array( "Folder compressed" );
		endif;
		
		// Check if debugging is enabled
		if( DEBUG === true ) :
			// Display debugging information
			foreach( $command_output as $cmd_output ) :
				OutputHandler::displayOutput( "[%lightgreen%DEBUG%lightgray%] [%lightblue%{$backupDir}%lightgray%]\t" . $cmd_output, $this -> color );
			endforeach;
		endif;
	
		// Check if it is a simulated run	
		if( !$this -> simulation ) :
			exec("rm -rf {$backupDir}");
		endif;
		SR_Agent::Log( APPID, SystemReporter::MSG_SUCCESS, "Backup complete for " . date("d/m/Y") );
	}
}

## Failsafe
if( !class_exists( "OutputHandler" ) ) :
	class OutputHandler
	{
		static public function displayOutput($output, $color = false)
		{
			$o = $output;
			$colors = array(
				"%lightgray%"   => "\033[0;30m",
				"%darkgrey%"		=> "\033[1;30m",
				"%blue%"				=> "\033[0;34m",
				"%lightblue%"		=> "\033[1;34m",
				"%green%"			  => "\033[0;32m",
				"%lightgreen%"	=> "\033[1;32m",
				"%cyan%"			  => "\033[0;36m",
				"%lightcyan%"		=> "\033[1;36m",
				"%red%"				  => "\033[0;31m",
				"%lightred%"		=> "\033[1;31m",
				"%purple%"			=> "\033[0;35m",
				"%lightpurple%"	=> "\033[1;35m",
				"%brown%"			  => "\033[0;33m",
				"%yellow%"			=> "\033[1;33m",
				"%lightgray%"		=> "\033[0;37m",
				"%white%"			  => "\033[1;37m"
			);
			foreach( $colors as $key => $value ) :
				if( $color === true ) :
					$o = str_replace( $key, $value, $o );
				else :
					$o = str_replace( $key, "", $o );
				endif;
			endforeach;
			
			echo $o . "\n";
		}
	}
endif;

// Setup default values
$simulation = false;
$color = false;
$version = false;

// Get argument list from command line and setup variable accordingly
foreach( $argv as $arg ) :
	switch( $arg ) :
		case "--simulation"	:	$simulation = true;
													break;
		case "--color"			:	$color = true;
													break;
		case "--version"		:	$version = true;
													break;
	endswitch;
endforeach;

// Check if version was not called
if( $version === false ) :
	$backup_system = new MySQL_Backup_System( $simulation, $color );
	echo $backup_system -> runBackups();
	OutputHandler::displayOutput("%lightblue%System run complete...%lightgray%", $color);
else :
	$date = "2010/05/07";
	$version_info = <<<VERSION

MySQL Backup Manager Script, {$date}
Author\t: %white%JA Clarke%lightgray%
Version\t: %white%{$system_version}%lightgray%
Usage\t:
	%lightblue%php %white%mysql_backup_manager.php %lightgreen%[--simulation] [--color] [--version]%lightgray%

%white%--simulation%lightgray%\t:\tRuns a simulated MySQL backup (useful for debugging)
%white%--color%lightgray%\t\t:\tDisplays the output as color formatted
%white%--version%lightgray%\t:\tDisplays this information
\n
VERSION;
	OutputHandler::displayOutput( $version_info, true );
endif;
exit(0);
?>
