<?php
/**
 * Migrations is a CakePHP shell script that runs your database migrations to the specified schema
 * version. If no version is specified, migrations are run to the latest version.
 *
 * Run 'cake migrate help' for more info and help on using this script.
 *
 * Heavily based on Joel Moss' Migrations shell ( http://joelmoss.info ) but uses cake's internal functions instead of PEAR::MDB2
 * and doesn't have some methods I found unnecessary.
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright   Copyright 2008, Georgi Momchilov
 * @copyright   Copyright 2010, John Hobbs
 * @link        http://ovalpixels.com
 * @author      Georgi Momchilov
 * @since       CakePHP(tm) v 1.2
 * @license     http://www.opensource.org/licenses/mit-license.php The MIT License
 *
*/

uses('file', 'folder');
App::import('vendor','spyc');
App::import('vendor','migrations');


class MigrateShell extends Shell {

    var $sConnection = 'default';
    var $aMigrations;
    var $oMigrations;

    /**
    * Initializes some paths and checks for the required classes
    */
    function startup(){
        define('MIGRATIONS_PATH', APP_PATH .'config' .DS. 'migrations');

        if(isset($this->params['c'])) $this->sConnection = $this->params['c'];
        if(isset($this->params['connection'])) $this->sConnection = $this->params['connection'];

        if( !class_exists( 'Migrations' ) )
            $this->error( 'File not found', 'Migrations class is needed for this shell to run. Could not be found - exiting.' );

        $this->oMigrations = new Migrations( $this->sConnection );

        // set table type for oMigrations from "-t" param if specified
        $this->_setTableType();

        $this->_welcome();
        $this->out('App : '. APP_DIR);
        $this->out('Path: '. ROOT . DS . APP_DIR);
        $this->_getMigrationVersion();
        $this->out('');
        $this->out('Current schema version: '.$this->iCurrent_version);
        $this->out('');
        $this->_getMigrations();
    }

    /**
    * Main method: migrates to the latest version.
    */
    function main(){

        if( !isset( $this->params['v'] ) ){
            $this->_migrate_to_version($this->iMigration_count);
        }
        else{
            $iTo_v = intval( $this->params['v'] );
            if( $iTo_v > $this->iMigration_count || $iTo_v < 0 ){
                $this->out('  ** Version number entered ('.$iTo_v.') does not exist. **');
                $this->out('');
            }
            else{
                $this->_migrate_to_version( $iTo_v );
            }
        }

        $this->out('Migrations completed.');
        $this->out('');
        $this->hr();
        $this->out('');
        exit;
    }

    /**
    * Generates an YAML file from the current DB schema
    */
    function generate(){
        $this->out('Generating full schema YAML file schema.yml...');
        $this->out('');
        $this->hr();
        $this->out('');
        $oFile = new File( MIGRATIONS_PATH . DS . 'schema.yml', true );
        if( !$oFile->writable() ){
            $this->out( '' );
            $this->out( 'Your migrations folder is not writable - I could not write the file schema.yml . Please check your permissions.' );
            $this->out('');
            exit;
        }
        $oFile->write( $this->oMigrations->generate() );
        $this->out( 'Schema file ( schema.yml ) successfully written!' );
        $this->out('');
    }

        /**
         * Generates a new empty migration file with the correct revision number.
         */
        function create(){
                if (!count($this->args) > 0){
                        $this->out('No file name specified. Exiting...');
                        return;
                }

                $migration_name = $this->args[0];

                if (preg_match("/[^0-9A-Za-z\-]/", $migration_name)) {
                        $this->out('Please use only alphanumeric characters and dashes on your migration name.');
                        return;
                }

                $oFolder = new Folder(MIGRATIONS_PATH, true, 0777);
                $current_files = $oFolder->find('[0-9]{3}\_' . $migration_name . '\.yml');

        if (count($current_files) > 0){
                $this->out('You already have a migration with that name. Please choose another name.');
                        return;
        }

                $version = $this->iMigration_count + 1;

                $this->out('Creating new migration file...');
                $this->out('New migration revision is ' . $version);
                $this->out('');
                $this->hr();

                $contents = array("DOWN" => "","UP" => "");
                $yaml = new Spyc();

                $file_content = $yaml->YAMLDump($contents);
                $file_name = str_pad($version, 3, "0", STR_PAD_LEFT) . '_' . $migration_name . '.yml';
                $oFile = new File(MIGRATIONS_PATH . DS . $file_name, true);
                $oFile->write($file_content);
                $this->out('');
                $this->out('Migration file (' . $file_name . ') created succesfully!');
        }

    /**
    * Migrates down to the previous version
    */
    function down(){
        $this->iTo_version = ($this->iCurrent_version === 0) ? $this->iCurrent_version : $this->iCurrent_version - 1;
        $this->_run();
        $this->out('Migrations completed.');
        $this->out('');
        $this->hr();
        $this->out('');
        exit;
    }

    /**
    * Migrates up to the next version
    */
    function up(){
        $this->iTo_version = ($this->iCurrent_version == $this->iMigration_count) ? $this->iCurrent_version :      $this->iCurrent_version + 1;
        $this->_run();
        $this->out('Migrations completed.');
        $this->out('');
        $this->hr();
        $this->out('');
        exit;
    }

    /**
    * Reset migration version to zero without running migrations up or down and drops all tables
    */
    function reset(){
        $this->iTo_version = 0;
        $aTables = $this->oMigrations->oDb->listSources();
        foreach( $aTables as $sTable )if( $sTable !== 'schema_info' ){
            $this->oMigrations->oDb->query( $this->oMigrations->drop_table( $sTable ) );
        }
        $this->_updateVersion(0);
        $this->hr();
        $this->out('');
        $this->out('  ** Schema reset **');
        $this->out('');
        $this->out('');
        $this->out('Migrations completed.');
        $this->out('');
        $this->hr();
        $this->out('');
        exit;
    }

    /**
    * Runs all migrations from the current version down and back up to the latest version.
    */
    function all(){
        if( $this->iCurrent_version > 0 )
            $this->_migrate_to_version(0);
        $this->_migrate_to_version($this->iMigration_count);
        $this->out('');
        $this->hr();
        $this->out('');
        $this->out('All migrations completed.');
        $this->out('');
        $this->hr();
        $this->out('');
        exit;
    }

    /**
    * Modifies the out method for prettier formatting
    *
    * @param string $sString String to output.
    * @param boolean $bNewline If true, the outputs gets an added newline.
    */
    function out($sString, $bNewline = true) {
        return parent::out("  ".$sString, $bNewline);
    }

    /**
    * Help method
    */
    function help(){
        $this->hr();
        $this->out('');
        $this->out('Database migrations is a version control system for your database,');
        $this->out('allowing you to migrate your database schema between versions.');
        $this->out('');
        $this->out('Each version is depicted by a migration file written in YAML and must');
        $this->out('include an UP and DOWN section. The UP section is parsed and run when');
        $this->out('migrating up and vice versa.');
        $this->out('');
        $this->hr();
        $this->out('');
        $this->out('COMMAND LINE OPTIONS');
        $this->out('');
        $this->out('  cake migrate');
        $this->out('    - Migrates to the latest version (the last migration file)');
        $this->out('');
        $this->out('  cake migrate -v [version number]');
        $this->out('    - Migrates to the version specified [version number]');
        $this->out('');
        $this->out('  cake migrate reset');
        $this->out('    - Resets the current version to 0 and drops all tables.');
        $this->out('');
        $this->out('  cake migrate all');
        $this->out('    - Migrates down to 0 and back up to the latest version');
        $this->out('');
        $this->out('  cake migrate down');
        $this->out('    - Migrates down to the previous current version');
        $this->out('');
        $this->out('  cake migrate up');
        $this->out('    - Migrates up from the current to the next version');
        $this->out('');
        $this->out('  cake migrate generate');
        $this->out('    - Write an YAML file out of your current DB schema');
        $this->out('');
        $this->out('  cake migrate create <migration_name>');
        $this->out('    - Creates an empty YAML file with the specfied name');
        $this->out('');
        $this->out('  cake migrate help');
        $this->out('    - Displays this Help');
        $this->out('');
        $this->out('  cake migrate syntax');
        $this->out('    - Displays migration syntax help (use a pager!)');
        $this->out('');
        $this->out("    append '-c [connection]' to the command if you want to specify the");
        $this->out('    connection to use from database.php. By default it uses "default"');
        $this->out('');
        $this->out("    append '-t [table type]' to the command if you want to specify the");
        $this->out('    table type (known as ENGINE in MySQL). By default tables will be created');
        $this->out('    accordingly to your DB config. Allowed one of these values:');
        $this->out('');
        $this->out('    ARCHIVE');
        $this->out('    CSV');
        $this->out('    EXAMPLE');
        $this->out('    FEDERATED');
        $this->out('    HEAP');
        $this->out('    InnoDB');
        $this->out('    MEMORY');
        $this->out('    MERGE');
        $this->out('    MyISAM');
        $this->out('    NDBCLUSTER');
        $this->out('');
        $this->out('');
        $this->out('');
        $this->out('');
        $this->out('For more information and for the latest release of this and others,');
        $this->out('go to http://ovalpixels.com');
        $this->out('');
        $this->hr();
        $this->out('');
    }

    /**
    * Aliases for the help method
    */
    function h() { $this->help(); }

    /**
    * Private method used to alter the destination version and run the migrating method ( _run )
    *
    * @access protected
    * @param integer #iNewVersion The destination version
    */
    function _migrate_to_version( $iNew_version ){
            if( !isset( $this->iTo_version ) || $iNew_version != $this->iTo_version ){
                $this->iTo_version = $iNew_version;
                $this->_run();
            }
    }

    /**
    * Protected method which does all the dirty work. Compares the destination and current version of the schema and runs the respective UPs or DOWNs
    */
    function _run(){
        $this->hr();

        if( $this->iCurrent_version == $this->iTo_version ){
            $this->out('');
            $this->out('  ** Migration version is the same as the current version **');
            $this->out('');
            $this->hr();
            $this->out('');
            exit;
        }
        if ($this->iMigration_count === 0){
            $this->out('');
            $this->out('  ** No migrations found **');
            $this->out('');
            $this->hr();
            $this->out('');
            exit;
        }
        $iNew_version = $this->iTo_version;

        if (!is_numeric($iNew_version)){
            $this->out('');
            $this->out('  ** Migration version number ('.$iNew_version.') is invalid. **');
            $this->out('');
            $this->hr();
            $this->out('');
            exit;
        }
        if ($iNew_version > $this->iMigration_count){
            $this->out('');
            $this->out('  ** Version number entered ('.$iNew_version.') does not exist. **');
            $this->out('');
            $this->hr();
            $this->out('');
            exit;
        }

        $sDirection = ($iNew_version < $this->iCurrent_version) ? 'down' : 'up';
        if ($sDirection == 'down') usort($this->aMigrations, array($this, '_downMigrations'));
        elseif ($sDirection == 'up') usort($this->aMigrations, array($this, '_upMigrations'));

        $this->out('');
        $this->out("Migrating database $sDirection from version {$this->iCurrent_version} to $iNew_version ...");
        $this->out('');

        foreach($this->aMigrations as $sMigration_name){
            preg_match("/^([0-9]+)\_(.+)(\.yml)$/", $sMigration_name, $aMatch);
            $iNum = $this->_versionIt($aMatch[1]);
            $sName = Inflector::humanize($aMatch[2]);

            if ($sDirection == 'up'){
                if ($iNum <= $this->iCurrent_version) continue;
                if ($iNum > $iNew_version) break;
            }
            elseif( $sDirection == 'down' ){
                if ($iNum > $this->iCurrent_version) continue;
                if ($iNum == $iNew_version) break;
            }

            $this->out("  [$iNum] $sName ...");

            $rRes = $this->_loadMigration(MIGRATIONS_PATH .DS. $sMigration_name, $sDirection);
            if ($rRes === true){
                $this->out('');
                if ($sDirection == 'up'){
                    $this->_updateVersion( 'version+1' );
                }
                else{
                    $this->_updateVersion( 'version-1' );
                }
            }
            elseif( is_numeric( $rRes ) && $rRes < 1 ){
                $this->out('Generic error: '.$rRes );
                $this->hr();
                exit;
            }
            else{
                $this->out("  ERROR: ");
                foreach( $rRes as $aErr ){
                    $this->out('Query: '.$aErr['sql']);
                    $this->out('');
                    $this->out('Error: '.$aErr['error']);
                }
                $this->hr();
                exit;
            }
        }
    }

    /**
    * Loads the file with the YAML migration schema and runs the generates the SQL script for the specified direction
    *
    * @access protected
    * @param string $sFile Path to the file
    * @param string $sFile Path to the file
    * @return mixed False on failure and a SQL command on success
    */
    function _loadMigration( $sFile, $sDirection ){
        if( !$bLoad = $this->oMigrations->load($sFile) ){
            return $bLoad;
        }
        else
            return $this->oMigrations->{$sDirection}();
    }

    /**
    * An internal sort method - used with usort(). Sorts from top to bottom
    *
    * @access protected
    * @param string $sA Name of a migration ( with the number in front )
    * @param string $sB Name of a migration ( with the number in front )
    * @return int < = >
    */
    function _upMigrations($sA, $sB){
        list($aStr) = explode('_', $sA);
        list($bStr) = explode('_', $sB);
        $aNum = (int)$aStr;
        $bNum = (int)$bStr;
        if ($aNum == $bNum){
            return 0;
        }
        return ($aNum > $bNum) ? 1 : -1;
    }

    /**
    * An internal sort method - used with usort(). Sorts from bottom to top
    *
    * @access protected
    * @param string $sA Name of a migration ( with the number in front )
    * @param string $sB Name of a migration ( with the number in front )
    * @return int < = >
    */
    function _downMigrations($sA, $sB){
        list($aStr) = explode('_', $sA);
        list($bStr) = explode('_', $sB);
        $aNum = (int)$aStr;
        $bNum = (int)$bStr;
        if ($aNum == $bNum) {
            return 0;
        }
        return ($aNum > $bNum) ? -1 : 1;
    }

    /**
    * Gets the current schema version from the DB. If schema_info doesn't exist - it tries to create it.
    *
    * @access protected
    * @return int Current schema version
    */
    function _getMigrationVersion(){
        //load tables and see if schema_info already exists. If not, create it
        $sTables = $this->oMigrations->oDb->listSources();
        if( !in_array( $this->oMigrations->oDb->config['prefix'].'schema_info', $sTables ) ){
            $this->oMigrations->oDb->query(
                $this->oMigrations->create_table(
                    'schema_info',
                    array(  0 => 'no_dates',
                            'version' => array( 'type' => 'int', 'length' => 3, 'default' => '0' ) ),
                    $this->oMigrations->sTableType
                 )
            );
            //feed it with some data
            App::import('model');
            $oTemp_model = new Model( false, 'schema_info' );
            $oTemp_model->saveField( 'version', '0' );
            $this->iCurrent_version = 0;

        }
        else{
            App::import('model');
            $oTemp_model = new Model( false, 'schema_info' );
            $this->iCurrent_version = $oTemp_model->field( 'version' );
        }
    }

    /**
    * Update the current shchema version in the database
    *
    * @access protected
    * @param mixed $sVersion The new schema version. Can be either an integer ( 1, 2, 3 ) or an expression ( version + 1 )
    * @return void
    */
    function _updateVersion($sVersion){
        App::import('model');
        $oTemp_model = new Model(false, 'schema_info' );
        $oTemp_model->updateAll( array( 'version' => $sVersion ) );

        $this->_getMigrationVersion();
    }

    /**
    * Loads the migration yaml files into an array ( self::aMigrations )
    *
    * @access protected
    * @return void
    */
    function _getMigrations(){
        $oFolder = new Folder(MIGRATIONS_PATH, true, 0777);
        $this->aMigrations = $oFolder->find("[0-9]+_.+\.yml");
        usort($this->aMigrations, array($this, '_upMigrations'));
        $this->iMigration_count = count($this->aMigrations);
    }

    /**
    * Converts migration number to a minimum three digit number.
    *
    * @param $iNum The number to convert
    * @return integer The converted three digit number
    */
    function _versionIt($iNum){
        switch (strlen($iNum)){
            case 1:
                return '00'.$iNum;
            case 2:
                return '0'.$iNum;
            default:
                return $iNum;
        }
    }

    /**
    * Welcome method
    */
    function _welcome(){
        $this->out('');
        $this->out(' __  __  _  _  __     ___     __   __   __  ___    __  _  _  __ ');
        $this->out('|   |__| |_/  |__    | | | | | _  |__| |__|  |  | |  | |\ | |__ ');
        $this->out('|__ |  | | \_ |__    | | | | |__| | \_ |  |  |  | |__| | \|  __|');
        $this->out('');
    }

    /**
    * Show some syntax help
    * @author John Hobbs
    */
    function syntax () {
      $this->hr();
      $this->out( '[ Migration Syntax Tips ]' );
      $this->hr();
      $this->out( '* You can only do one action per migration!' );
      $this->out( '* This guide shows the full syntax, discover & user the short syntax at your own risk.' );
      $this->out( '* You don\'t need to specify id or created/modified fields, these are added automatically.' );
      $this->out( '* If you don\'t want those fields, specify no_id or no_dates in your table creation.' );
      $this->out( '' );
      $this->hr();
      $this->out( '[ Available Field Types ]' );
      $this->hr();
      foreach( $this->oMigrations->aTypes as $type )
              $this->out( "     $type" );
      $this->out( '' );
      $this->hr();
      // These are scraped by hand from Migrations::load
      $actions = array(
              'create_table',
              'create_tables',
              'drop_table',
              'drop_tables',
              'rename_table',
              'rename_tables',
              'merge_table',
              'merge_tables',
              'truncate_table',
              'truncate_tables',
              'add_field | add_column',
              'add_fields | add_columns',
              'alter_field | alter_column',
              'alter_fields | alter_columns',
              'drop_field | drop_column',
              'drop_fields | drop_columns',
              'query',
              'queries',
      );
      $this->out( '[ Available Actions ]' );
      $this->hr();
      foreach( $actions as $action )
          $this->out( "     $action" );
      $this->out( '' );
      $this->hr();
      $this->out( '[ Example: Create/Delete A Table ]' );
      $this->hr();
      $this->out( ' UP:
       create_table:
         users:
           name:
             type: string
             default: false
             length: 255
             - not_null
           age: int
           is_active: bool
     DOWN:
       drop_table: users' );
      $this->out( '' );
      $this->hr();
      $this->out( '[ Example: Add/Remove A Field ]' );
      $this->hr();
      $this->out( ' UP:
       add_field:
         users:
           last_name:
             type: string
             length: 10
     DOWN:
       drop_field:
         users:
           - last_name' );
      $this->out( '' );
      $this->hr();
      $this->out( '[ Example: Rename A Field ]' );
      $this->hr();
      $this->out( ' UP:
       alter_field:
         users:
           name: first_name
     DOWN:
       alter_field:
         users:
           first_name: name' );
      $this->out( '' );
      $this->hr();
    }

    /**
     *  Set tables type (known as ENGINE in MySQL) for $this->oMigrations object.
     *  If setted this table type will be used in any create_table method call
     *
     *  @author Serge Bedzhik
     *  @author Max Bidyuk
     */
    public function _setTableType(){
      if( isset( $this->params['t']) ){
          if(in_array($this->params['t'], $this->oMigrations->aTableTypes)){
              $this->oMigrations->sTableType = $this->params['t'];
          }
          else{
              $this->out('');
              $this->out('  ** Table type entered ('.$this->params['t'].') does not supported! **');
              $this->out('  ** To get more information about options use "migration help" command **');
              $this->out('');
              $this->hr();
              $this->out('');
              exit;
          }
        }
    }
}
