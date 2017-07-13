<?php
/*-------------------------------------------------------+
| Holland Open Source - E-boekhouden importer            |
| Copyright (C) 2017 Holland Open Source                 |
| Author: Daniël (daniel -at- hollandopensource.nl)      |
| https://hollandopensource.nl/                          |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL v3 license. You can redistribute it and/or  |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/
// utility function
function _eboekhoudenimporter_helper_startswith($string, $prefix) {
  return substr($string, 0, strlen($prefix)) === $prefix;
}
/**
 *
 * @package nl.hollandopensource.eboekhouden
 * @copyright GNU Affero General Public License
 * $Id$
 *
 */
class CRM_Eboekhouden_Banking_PluginImpl_Importer_Eboekhouden extends CRM_Banking_PluginModel_Importer {
  /**
   * class constructor
   */ function __construct($config_name) {
    parent::__construct($config_name);
    // read config, set defaults
    $config = $this->_plugin_config;
    if (!isset($config->delimiter))      $config->delimiter = ',';
    if (!isset($config->header))         $config->header = 1;
    if (!isset($config->warnings))       $config->warnings = true;
    if (!isset($config->skip))           $config->skip = 0;
    if (!isset($config->line_filter))    $config->line_filter = NULL;
    if (!isset($config->defaults))       $config->defaults = array();
    if (!isset($config->rules))          $config->rules = array();
    if (!isset($config->drop_columns))   $config->drop_columns = array();
    if (!isset($config->username))       $config->username = civicrm_api3('Setting', 'getvalue', array(
                                                               'name' => "eboekhouden_username",
                                                             ));
    if (!isset($config->seccode1))       $config->seccode1 = civicrm_api3('Setting', 'getvalue', array(
                                                               'name' => "eboekhouden_seccode1",
                                                             ));
    if (!isset($config->seccode2))       $config->seccode2 = civicrm_api3('Setting', 'getvalue', array(
                                                               'name' => "eboekhouden_seccode2",
                                                             ));
  }

  /**
   * will be used to avoid multiple account lookups
   */
  protected $account_cache = array();

  /** 
   * the plugin's user readable name
   * 
   * @return string
   */
  static function displayName()
  {
    return 'E-boekhouden Importer';
  }

  /** 
   * Report if the plugin is capable of importing files
   * 
   * @return bool
   */
  static function does_import_files()
  {
    return false;
  }

  /** 
   * Report if the plugin is capable of importing streams, i.e. data from a non-file source, e.g. the web
   * 
   * @return bool
   */
  static function does_import_stream()
  {
    return true;
  }

  /** 
   * Test if the configured source is available and ready
   * 
   * @var 
   * @return TODO: data format?
   */
  function probe_stream( $params )
  {
    return true;
  }

  /** 
   * Import the given file
   * 
   * @return TODO: data format? 
   */
  function import_stream( $params )
  {
    // begin
    $config = $this->_plugin_config;
    $this->reportProgress(0.0, sprintf("Creating SOAP connection with username '%s'...", $config->username));
    $soapClient = new SoapClient("https://soap.e-boekhouden.nl/soap.asmx?WSDL");

    // open session and get sessionid
    $soapParams = array(
      "Username" => $config->username,
      "SecurityCode1" => $config->seccode1,
      "SecurityCode2" => $config->seccode2
    );
    $soapResponse = $soapClient->__soapCall("OpenSession", array($soapParams));
    $SessionID = $soapResponse->OpenSessionResult->SessionID;

    // request the last 500 mutations
    $soapParams = array(
      "SecurityCode2" => $config->seccode2,
      "SessionID" => $SessionID,
      "cFilter" => array(
        "MutatieNr" => 0,
        "Factuurnummer" => "",
        "DatumVan" => "2017-01-01",
        "DatumTm" => "2100-01-01"
      )
    );
    $soapResponse = $soapClient->__soapCall("GetMutaties", array($soapParams));
    
    
    
    
    $batch = $this->openTransactionBatch();
    
    
    
    // close session
    $soapParams = array(
      "SessionID" => $SessionID
    );
    $soapResponse = $soapClient->__soapCall("CloseSession", array($soapParams));

    //TODO: customize batch params

    if ($this->getCurrentTransactionBatch()->tx_count) {
      // we have transactions in the batch -> save
      if ($config->title) {
        // the config defines a title, replace tokens
        $this->getCurrentTransactionBatch()->reference = $config->title;
      } else {
        $this->getCurrentTransactionBatch()->reference = "CSV-File {md5}";
      }
      $this->closeTransactionBatch(TRUE);
    } else {
      $this->closeTransactionBatch(FALSE);
    }
    $this->reportDone();
  }

  /**
   * Extract the value for the given key from the resources (line, btx).
   */
  protected function getValue($key, $btx, $line=NULL, $header=array()) {
    // get value
    if (_eboekhoudenimporter_helper_startswith($key, '_constant:')) {
      return substr($key, 10);
    } else if ($line && is_int($key)) {
      return $line[$key];
    } else {
      $index = array_search($key, $header);
      if ($index!==FALSE) {
        if (isset($line[$index])) {
          return $line[$index];  
        } else {
          // this means, that the column does exist in the header, 
          //  but not in this row => bad import
          return NULL;
        }
      } elseif (isset($btx[$key])) {
        // this is not in the line, maybe it's already in the btx
        return $btx[$key];
      } else {
        if ($this->_plugin_config->warnings) {
          error_log("nl.hollandopensource.eboekhouden: EboekhoudenImporter - Cannot find source '$key' for rule or filter.");
        }
      }
    }
    return '';
  }

  /**
   * executes an import rule
   */
  protected function apply_rule($rule, $line, &$btx, $header) {
    // get value
    $value = $this->getValue($rule->from, $btx, $line, $header);
    // check if-clause
    if (isset($rule->if)) {
      if (_eboekhoudenimporter_helper_startswith($rule->if, 'equalto:')) {
        $params = explode(":", $rule->if);
        if ($value != $params[1]) return;
      } elseif (_eboekhoudenimporter_helper_startswith($rule->if, 'matches:')) {
        $params = explode(":", $rule->if);
        if (!preg_match($params[1], $value)) return;
      } else {
        print_r("CONDITION (IF) TYPE NOT YET IMPLEMENTED");
        return;
      }
    }
    // execute the rule
    if (_eboekhoudenimporter_helper_startswith($rule->type, 'set')) {
      // SET is a simple copy command:
      $btx[$rule->to] = $value;
    } elseif (_eboekhoudenimporter_helper_startswith($rule->type, 'append')) {
      // APPEND appends the string to a give value
      if (!isset($btx[$rule->to])) $btx[$rule->to] = '';
      $params = explode(":", $rule->type);
      if (isset($params[1])) {
        // the user defined a concat string
        $btx[$rule->to] = $btx[$rule->to].$params[1].$value;
      } else {
        // default concat string is " "
        $btx[$rule->to] = $btx[$rule->to]." ".$value;
      }
    } elseif (_eboekhoudenimporter_helper_startswith($rule->type, 'trim')) {
      // TRIM will strip the string of 
      $params = explode(":", $rule->type);
      if (isset($params[1])) {
        // the user provided a the trim parameters
        $btx[$rule->to] = trim($value, $params[1]);
      } else {
        $btx[$rule->to] = trim($value);
      }
    } elseif (_eboekhoudenimporter_helper_startswith($rule->type, 'replace')) {
      // REPLACE will replace a substring
      $params = explode(":", $rule->type);
      $btx[$rule->to] = str_replace($params[1], $params[2], $value);
    } elseif (_eboekhoudenimporter_helper_startswith($rule->type, 'format')) {
      // will use the sprintf format
      $params = explode(":", $rule->type);
      $btx[$rule->to] = sprintf($params[1], $value);
    } elseif (_eboekhoudenimporter_helper_startswith($rule->type, 'constant')) {
      // will just set a constant string
      $btx[$rule->to] = $rule->from;
    } elseif (_eboekhoudenimporter_helper_startswith($rule->type, 'strtotime')) {
      // STRTOTIME is a date parser
      $params = explode(":", $rule->type, 2);
      if (isset($params[1])) {
        // the user provided a date format
        $datetime = DateTime::createFromFormat($params[1], $value);
        if ($datetime) {
          $btx[$rule->to] = $datetime->format('YmdHis');  
        }
      } else {
        $btx[$rule->to] = date('YmdHis', strtotime($value));
      }
    } elseif (_ebeokhoudenimporter_helper_startswith($rule->type, 'amount')) {
      // AMOUNT will take care of currency issues, like "," instead of "."
      $btx[$rule->to] = str_replace(",", ".", $value);
    } elseif (_eboekhoudenimporter_helper_startswith($rule->type, 'regex:')) {
      // REGEX will extract certain values from the line
      $pattern = substr($rule->type, 6);
      $matches = array();
      if (preg_match($pattern, $value, $matches)) {
        // we found it!
        $btx[$rule->to] = $matches[1];
      } else {
        // check, if we should warn: (not set = 'warn' for backward compatibility)
        if (!isset($rule->warn) || $rule->warn) {
          $this->reportProgress(CRM_Banking_PluginModel_Base::REPORT_PROGRESS_NONE, 
            sprintf(ts("Pattern '%s' was not found in entry '%s'."), $pattern, $value));          
        }
      }
    } else {
      print_r("RULE TYPE NOT YET IMPLEMENTED");
    }    
  }

  /** 
   * Test if the given file can be imported
   * 
   * @var 
   * @return TODO: data format? 
   */
  function probe_file( $file_path, $params )
  {
    return false;
  }

  /** 
   * Import the given file
   * 
   * @return TODO: data format? 
   */
  function import_file( $file_path, $params )
  {
    $this->reportDone(ts("Importing files not supported by this plugin."));
  }
}
