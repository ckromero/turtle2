<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/* Parser - process

  STEP1- Sensor Registrations
  - Each line gets parsed into a key/value pair.
  - If the expected label is not contained in the key, the file is assumed bad and a flag is set.
  - If the expected value fails its regex pattern, the file is assumed bad and a flag is set.
    
  STEP 2
  - If the file is error free, the database is checked to see if that file's event_date and sensor_id already exist in NESTS.
  - If it already exists, the data is updated in NESTS, SENSORS, and COMMUNICATORS after an insert to EVENTS. 
  - If it does not exist, the file data is inserted (same set of tables).
  - If the file is flagged as bad, all database actions are skipped and the file is moved to malformed_reports.
  - In all cases a log will record the outcome.
  
  So if there is any data missing, misspelled, or out of place in the file, the entire file is rejected. 
  Rejected files can be found in the problem directory. Check the log for the issue. 
  The file can be edited and put back into the reports folderto be read again by the parser.
  You could use this same technique to make a change to an existing report if that were ever necessary. 
  
  An event is the same as a log entry for a registration or log entry for a report (set of records from a sensor).
 
 Potential Confusion:
 
  The word "log" may refer to both a typical application log, as well as to the device report that's ftp'd to the server.
  The word "report" may refer to both the device log, as well as to a specific device log that contains records.
 
 */

class Parser extends CI_Controller {

	function __construct()
	{
		parent::__construct();		
		$this->load->model('parse', 'parsemodel');
		$this->load->model('log', 'logmodel');		
	}
	
	function index()
	{    
    // Last modification date that was stored after the last batch of parsed files
    $lastparse_filemtime = $this->logmodel->read_lastparse_filemtime();
    
    // Get files with a mod date newer than $lastparse_filemtime 
    $files = $this->parsemodel->getFiles($lastparse_filemtime);
    $logEntry_typecode = '';
 
    if ($files) {
  
      $newest_filemtime = $lastparse_filemtime;
      
      foreach ($files as $file_name) {                       

        // compare file to newest file in this batch so far
        $newest_filemtime = (filemtime($file_name) > $newest_filemtime) ? filemtime($file_name) : $newest_filemtime;
        
        $txt_file = trim(file_get_contents($file_name));      
        
        /* There can be several reports in one file, each terminated by '--end of report--'
         * If '--end of report--' is not found, it's a registration file, so split on \r\r */
         
        if (strpos($txt_file, '--end of report--') !== false) {
          $logEntries_arr = explode("--end of report--", trim($txt_file));  
        } else {
          $logEntries_arr = explode("\r\r", trim($txt_file));    
        }
        
        // In case files use \n\n, split on newline instead
        if ( count($logEntries_arr) == 1) {
          $logEntries_arr = explode("\n\n", $txt_file);
        } 
        
        // remove empty elements
        $logEntries_arr = array_filter($logEntries_arr); 

        // trim each element
        $logEntries_arr = array_map('trim', $logEntries_arr); 
        
        foreach ($logEntries_arr as $logEntry) {        
    
          $logEntry_lines = explode("\r", $logEntry);
  
          if ( count($logEntry_lines) == 1) {  
            // file doesn't use return character, so split on newline
            $logEntry_lines = explode("\n", $logEntry);
          } 
      
          $logEntry_lines = array_map('trim', $logEntry_lines); 
        
          // log title determines which parser to call
          $logEntry_typecode = strtoupper(substr($logEntry_lines[0], 0, 3));
 
          $data_fields = array();
          switch ($logEntry_typecode) 
          {          
            case 'REG': // REGISTRATION
              $data_fields = $this->parsemodel->parse_NestRegistration($logEntry_lines);
              
              if (!isset($data_fields['file_format_error'])) {                
                $eventexists = $this->parsemodel->eventExists($data_fields);               
                if (!$eventexists) {               
                  $data_fields['file_name'] = $file_name;  
                  $this->_processNestRegistration($data_fields); 
                }
              }
              else {
                $this->logmodel->logFailure($data_fields);
              }
            break;
                                         
            case 'REP': // REPORT    
              $data_fields = $this->parsemodel->parse_NestReport($logEntry_lines);

              if (!isset($data_fields['file_format_error'])) {  
                $reportexists = $this->parsemodel->reportExists($data_fields);                
                if (!$reportexists) {                 
                  $data_fields['file_name']       = $file_name; 
                  $data_fields['report_datetime'] = $data_fields['event_datetime'];
 
                  // capture header values from the report for records, before giving up data_fields[] to records
                  $report['num_records']        = $data_fields['num_records'];
                  $report['sensor_id']          = $data_fields['sensor_id'];
                  $report['report_datetime']    = $data_fields['report_datetime'];
                  $report['report_starttime']   = $data_fields['report_starttime'];
                  $report['secs_per_rec']       = $data_fields['secs_per_rec'];
                  
                  $reportid  = $this->_processNestReport($data_fields);                   
                  $nestreport = $this->parsemodel->getReportById($reportid);
                  $report['nest_id'] = $nestreport->nest_id;
                  $report['report_id'] = $nestreport->report_id;
                  
                  // element 11 will always be empty, but check anyway for now
                  if(empty($logEntry_lines[11])){
                  
                    $recordEntry_lines = array_slice($logEntry_lines,12);
                    
                    $records = $this->parsemodel->parse_NestRecords($recordEntry_lines);
                    $records['file_name']          = $file_name; 
                    $records['report_id']          = $report['report_id'];        
                    $records['num_records']        = $report['num_records'];  
                    $records['sensor_id']          = $report['sensor_id'];  
                    $records['event_datetime']     = $report['report_datetime'];  
                    $records['report_datetime']    = $report['report_datetime'];  
                    $records['report_starttime']   = $report['report_starttime'];
                    $records['secs_per_rec']       = $report['secs_per_rec'];
                    $records['nest_id']            = $report['nest_id'];  
  
                    // we dont have to test for records existence since that's done at the report level
                    $this->_processNestRecords($records); 
                   } 
                   else {
                    $data_fields['file_format_error'] = "Expected empty element in logEntry_lines array. See case REP in parser switch.";
                    $this->logmodel->logFailure($data_fields);                     
                   }                                                               
                }
              }
              else {
                $data_fields['file_name']  = $file_name; 
                $this->logmodel->logFailure($data_fields);
              }
              break;
              
            default:        
              $data_fields['file_name'] = $file_name;
              $data_fields['file_format_error'] = "File contains unknown event type.";   
          } 

        }//foreach entry
 
        // Save to a file, the newest modification date from this batch of parsed files
        $this->logmodel->write_lastparse_filemtime($newest_filemtime);

      }//foreach file
    } 
    else {      
      echo "No new files to read.<br>";
    }
    echo "<br>PARSER FINISHED!";
	}
		  
  function _processNestRegistration($data_fields)
  {    
    // override event_type in case it was sent from a "nest report"
    $data_fields['event_type'] = 'nest registration';
    $eventexists = $this->parsemodel->eventExists($data_fields);
    if (!$eventexists) {
      $this->parsemodel->dba_nestRegistration($data_fields);
      $this->logmodel->logSuccess($data_fields);     
    }
  }  

  function _processNestReport($data_fields)
  {   
    $eventexists  = $this->parsemodel->eventExists($data_fields);
    $activeSensor = $this->parsemodel->getActiveSensor($data_fields['sensor_id']);

    // Make sure the incoming registration is not older than what's already recorded
    if ($activeSensor) {
      $okToRegister = (!$eventexists and $activeSensor->sensor_lastuse < $data_fields['nest_date']) ? 1 : 0;
    } 
    else {
      $okToRegister = !$eventexists ? 1 : 0;
    }

    if ($okToRegister) {
      $this->_processNestRegistration($data_fields); 
    } 
    $report_id = $this->parsemodel->dba_nestReport($data_fields);
    $this->logmodel->logSuccess($data_fields);     
    return $report_id;        
  }

  function _processNestRecords($records)
  {       
    $numrecs = $records['num_records'];

    if ($numrecs) {
      $x=1;
      while ($x < $numrecs +1 ) {    
        $data_fields = $records[$x];
        $data_fields['temperature']     = $data_fields['temperature']/25.6;
        $data_fields['report_id']       = $records['report_id'];                      
        $data_fields['nest_id']         = $records['nest_id'];                                            
        $data_fields['report_id']       = $records['report_id'];                         
        $data_fields['record_num']      = $data_fields['record_num'] + 1;      
        $data_fields['record_datetime'] = date('Y-m-d H:i:s', 
                                            strtotime(
                                              $records['report_starttime']) 
                                              + ($records['secs_per_rec'] * $data_fields['record_num'] ));                          
        $x++;     
        $this->parsemodel->dba_nestRecord($data_fields);
      }
      $records['event_type'] = "nest record set";//cludge for log output only
      $this->logmodel->logSuccess($records);     
    } 
  }

}
/* EOF */




 