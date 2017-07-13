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
    if (!isset($config->line_filter))    $config->line_filter = NULL;
    if (!isset($config->defaults))       $config->defaults = array();
    if (!isset($config->rules))          $config->rules = array();
    if (!isset($config->drop_columns))   $config->drop_columns = array();
    if (!isset($config->progressfactor)) $config->progressfactor = 500;
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
    $this->reportProgress(0.0, sprintf("Config: '%s'", serialize($config)));
    $soapClient = new SoapClient("https://soap.e-boekhouden.nl/soap.asmx?WSDL");
    $line_nr = 1; // we want to skip the header (not yet implemented)

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
        "DatumVan" => date("Y-m-d", strtotime("-1 year")),
        "DatumTm" => date("Y-m-d")
      )
    );
    $soapResponse = $soapClient->__soapCall("GetMutaties", array($soapParams));
    $Mutations = $soapResponse->GetMutatiesResult->Mutaties;

    // make array if there is a result
    if(!is_array($Mutations->cMutatieList))
      $Mutations->cMutatieList = array($Mutations->cMutatieList);
    
    $batch = $this->openTransactionBatch();

    // loop through mutations
    foreach ($Mutations->cMutatieList as $payment_line) {
      // update stats
      $line_nr += 1;

      // check if we want to skip line (by filter)
      if (!empty($config->line_filter)) {
        $full_line = serialize($payment_line);
        if (!preg_match($config->line_filter, $full_line)) {
          $config->header += 1;  // bump line numbers if filtered out
          continue;
        }
      }
      // check encoding if necessary
      //TODO: needs to be rewritten to facilitate object
      if (isset($config->encoding)) {
        $decoded_line = array();
        foreach ($payment_line as $item) {
          array_push($decoded_line, mb_convert_encoding($item, mb_internal_encoding(), $config->encoding));
        }
        $line = $decoded_line;
      }
      // exclude ignored columns from further processing
      //TODO: needs to be rewritten to facilitate object
      if (!empty($config->drop_columns)) {
        foreach ($config->drop_columns as $column) {
          $index = array_search($column, $header);
          if ($index !== FALSE) {
            unset($line[$index]);
          }
        }
      }
      //TODO: needs to be rewritten to facilitate object
      if ($line_nr == $config->header) {
        // parse header
        if (sizeof($header)==0) {
          $header = $line;  
        }
      } else {
        // import payment
        $this->import_payment($payment_line, $line_nr, $params);
      }
    }
    
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

  protected function import_payment($line, $line_nr, $params) {
    $config = $this->_plugin_config;
    $progress = $line_nr/$config->progressfactor;
    
    // generate entry data
    $raw_data = serialize($line);
    $btx = array(
      'version' => 3,
      'currency' => 'EUR',
      'type_id' => 0,                               // TODO: lookup type ?
      'status_id' => 0,                             // TODO: lookup status new
      'data_raw' => $raw_data,
      'sequence' => $line_nr-$config->header,
    );
    // set default values from config:
    foreach ($config->defaults as $key => $value) {
      $btx[$key] = $value;
    }
    // execute rules from config:
    //TODO: implement rules for object or remove functionallity
    foreach ($config->rules as $rule) {
      try {
        $this->apply_rule($rule, $line, $btx);
      } catch (Exception $e) {
        $this->reportProgress($progress, sprintf(ts("Rule '%s' failed. Exception was %s"), $rule, $e->getMessage()));
      }
    }
    // run filters
    if (isset($config->filter) && is_array($config->filter)) {
      foreach ($config->filter as $filter) {
        if ($filter->type=='string_positive') {
          // only accept string matches
          $value1 = $this->getValue($filter->value1, $btx, $line, $header);
          $value2 = $this->getValue($filter->value2, $btx, $line, $header);
          if ($value1 != $value2) {
            $this->reportProgress($progress, sprintf("Skipped line %d", $line_nr-$config->header));
            return;
          }
        }
      }
    }
    // look up the bank accounts
    foreach ($btx as $key => $value) {
      // check for NBAN_?? or IBAN endings
      if (preg_match('/^_.*NBAN_..$/', $key) || preg_match('/^_.*IBAN$/', $key)) {
        // this is a *BAN entry -> look it up
        if (!isset($this->account_cache[$value])) {
          $result = civicrm_api('BankingAccountReference', 'getsingle', array('version' => 3, 'reference' => $value));
          if (!empty($result['is_error'])) {
            $this->account_cache[$value] = NULL;
          } else {
            $this->account_cache[$value] = $result['ba_id'];
          }
        }
        if ($this->account_cache[$value] != NULL) {
          if (substr($key, 0, 7)=="_party_") {
            $btx['party_ba_id'] = $this->account_cache[$value];  
          } elseif (substr($key, 0, 1)=="_") {
            $btx['ba_id'] = $this->account_cache[$value];  
          }
        }
      }
    }
    // do some post processing
    if (!isset($config->bank_reference)) {
      // set MD5 hash as unique reference
      $btx['bank_reference'] = md5($raw_data);
    } else {
      // otherwise use the template
      $bank_reference = $config->bank_reference;
      $tokens = array(); 
      preg_match('/\{([^\}]+)\}/', $bank_reference, $tokens);
      foreach ($tokens as $key => $token_name) {
        if (!$key) continue;  // match#0 is not relevant
        $token_value = isset($btx[$token_name])?$btx[$token_name]:'';
        $bank_reference = str_replace("{{$token_name}}", $token_value, $bank_reference);
      }
      $btx['bank_reference'] = $bank_reference;
    }    
    // prepare $btx: put all entries, that are not for the basic object, into parsed data
    $btx_parsed_data = array();
    foreach ($btx as $key => $value) {
      if (!in_array($key, $this->_primary_btx_fields)) {
        // this entry has to be moved to the $btx_parsed_data records
        $btx_parsed_data[$key] = $value;
        unset($btx[$key]);
      }
    }
    $btx['data_parsed'] = json_encode($btx_parsed_data);
    // and finally write it into the DB
    $duplicate = $this->checkAndStoreBTX($btx, $progress, $params);
    // TODO: process duplicates or failures?
    $this->reportProgress($progress, sprintf("Imported line %d", $line_nr-$config->header));
    $this->reportProgress($progress, sprintf("Hey: '%s'", $line->MutatieNr . ' ' . $line->MutatieNr . ' ' . $line->Datum . ' ' . $line->Datum . ' ' . $line->MutatieRegels->cMutatieListRegel->BedragInvoer . ' ' . $line->Omschrijving));
    $this->reportProgress($progress, sprintf("Hey: '%s'", serialize($btx)));
  }

  /**
   * Extract the value for the given key from the resources (line, btx).
   */
  protected function getValue($key, $btx, $line=NULL, $header=array()) {
    $this->reportProgress(0.1, sprintf("$line->$key: '%s'", $line->$key));
    // get value
    if (_eboekhoudenimporter_helper_startswith($key, '_constant:')) {
      return substr($key, 10);
    } else {
      if (isset($line->$key)) {
        return $line->$key;
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
  protected function apply_rule($rule, $line, &$btx) {
    // get value
    $value = $this->getValue($rule->from, $btx, $line);
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
    } elseif (_eboekhoudenimporter_helper_startswith($rule->type, 'amount')) {
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
