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
    if (!isset($config->warnings))       $config->warnings = true;
    if (!isset($config->defaults))       $config->defaults = array();
    if (!isset($config->rules))          $config->rules = array();
    if (!isset($config->cursor))         $config->cursor = civicrm_api3('Setting', 'getvalue', array(
                                                             'name' => "eboekhouden_cursor",
                                                           ));
    if (!isset($config->soap_url))       $config->soap_url = civicrm_api3('Setting', 'getvalue', array(
                                                               'name' => "eboekhouden_soap_url",
                                                             ));
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
    $line_nr = 0;
    $batch = $this->openTransactionBatch();

    // get the mutations and process them
    $this->reportProgress(0.0, sprintf("Creating SOAP connection with username '%s'...<br>", $config->username));
    $soapClient = new SoapClient($config->soap_url);

    // open session and get sessionid
    $soapParams = array(
      "Username" => $config->username,
      "SecurityCode1" => $config->seccode1,
      "SecurityCode2" => $config->seccode2
    );
    $soapResponse = $soapClient->__soapCall("OpenSession", array($soapParams));
    $soapSessionId = $soapResponse->OpenSessionResult->SessionID;

    // request the last 500 mutations from the last year
    $soapParams = array(
      "SecurityCode2" => $config->seccode2,
      "SessionID" => $soapSessionId,
      "cFilter" => array(
        "MutatieNr" => 0,
        "MutatieNrVan" => $config->cursor + 1,
        "MutatieNrTm" => $config->cursor + 501,
        "Factuurnummer" => "",
        "DatumVan" => date("Y-m-d", strtotime("-1 year")),
        "DatumTm" => date("Y-m-d", strtotime("1 year"))
      )
    );
    $soapResponse = $soapClient->__soapCall("GetMutaties", array($soapParams));
    $Mutations = $soapResponse->GetMutatiesResult->Mutaties;
    if (isset($Mutations->cMutatieList)) {
      // make array if there is a result
      if (!is_array($Mutations->cMutatieList)) {
        $payment_lines = array($Mutations->cMutatieList);
      } else {
        $payment_lines = $Mutations->cMutatieList;
      }
      $this->process_payment_lines($payment_lines, $line_nr, $params);
    }

    // close session
    $soapParams = array(
      "SessionID" => $soapSessionId
    );
    $soapResponse = $soapClient->__soapCall("CloseSession", array($soapParams));

    //TODO: customize batch params
    if ($this->getCurrentTransactionBatch()->tx_count) {
      // we have transactions in the batch -> save
      if (isset($config->title)) {
        // the config defines a title, replace tokens
        $this->getCurrentTransactionBatch()->reference = $config->title;
      } else {
        $this->getCurrentTransactionBatch()->reference = "SOAP-import {md5}";
      }
      $this->closeTransactionBatch(TRUE);
    } else {
      $this->closeTransactionBatch(FALSE);
    }
    $this->reportDone();
  }
  protected function process_payment_lines($payment_lines, &$line_nr, $params) {
    $config = $this->_plugin_config;
    $newcursor = $this->getValue('MutatieNr', array(), get_object_vars(max($payment_lines)));
    $config->progressfactor = $newcursor - $config->cursor;
    civicrm_api3('Setting', 'create', array(
      'eboekhouden_cursor' => $newcursor,
    ));
    foreach ($payment_lines as $payment_line) {
      if (is_array($payment_line->MutatieRegels->cMutatieListRegel)) {
        $count = 0;
        $payment_array = $payment_line->MutatieRegels->cMutatieListRegel;
        foreach($payment_array as $arrayline) {
          $payment_line->MutatieRegels->cMutatieListRegel = $arrayline;
          $payment_arraylines[] = unserialize(serialize($payment_line));
          $count += 1;
        }
        $config->progressfactor += $count - 1;
        $this->process_payment_lines($payment_arraylines, $line_nr, $params);
        break 1;
      }
      // import payment
      $this->import_payment(get_object_vars($payment_line), $line_nr, $params);
    }
  }
  protected function import_payment($line, &$line_nr, $params) {
    $config = $this->_plugin_config;

    // update stats
    $line_nr += 1;
    $progress = $line_nr / $config->progressfactor;

    // generate entry data
    $raw_data = serialize($line);
    $btx = array(
      'version' => 3,
      'currency' => 'EUR',
      'type_id' => 0,                               // TODO: lookup type ?
      'status_id' => 0,                             // TODO: lookup status new
      'data_raw' => $raw_data,
      'sequence' => $line_nr,
    );

    // set default values from config:
    foreach ($config->defaults as $key => $value) {
      $btx[$key] = $value;
    }
    // execute rules from config:
    foreach ($config->rules as $rule) {
      try {
        $this->apply_rule($rule, $line, $btx);
      } catch (Exception $e) {
        $this->reportProgress($progress, sprintf(ts("Rule '%s' failed. Exception was %s"), $rule, $e->getMessage()));
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
    $this->reportProgress($progress, sprintf("Imported line %d<br>", $line_nr));
  }
  /**
   * Extract the value for the given key from the resources (line, btx).
   */
  protected function getValue($key, $btx, $line=NULL) {
    // get value
    if ($this->startsWith($key, '_constant:')) {
      return substr($key, 10);
    } else {
      if (isset($line[$key])) {
        return $line[$key];
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
      if ($this->startsWith($rule->if, 'equalto:')) {
        $params = explode(":", $rule->if);
        if ($value != $params[1]) return;
      } elseif ($this->startsWith($rule->if, 'matches:')) {
        $params = explode(":", $rule->if);
        if (!preg_match($params[1], $value)) return;
      } else {
        print_r("CONDITION (IF) TYPE NOT YET IMPLEMENTED");
        return;
      }
    }    // execute the rule
    if ($this->startsWith($rule->type, 'object')) {
      foreach ($rule->to as $childrule) {
        try {
          $this->apply_rule($childrule, get_object_vars($value), $btx);
        } catch (Exception $e) {
          $this->reportProgress($progress, sprintf(ts("Rule '%s' failed. Exception was %s"), $childrule, $e->getMessage()));
        }
      }
    } elseif ($this->startsWith($rule->type, 'set')) {
      // SET is a simple copy command:
      $btx[$rule->to] = $value;
    } elseif ($this->startsWith($rule->type, 'append')) {
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
    } elseif ($this->startsWith($rule->type, 'trim')) {
      // TRIM will strip the string of
      $params = explode(":", $rule->type);
      if (isset($params[1])) {
        // the user provided a the trim parameters
        $btx[$rule->to] = trim($value, $params[1]);
      } else {
        $btx[$rule->to] = trim($value);
      }
    } elseif ($this->startsWith($rule->type, 'replace')) {
      // REPLACE will replace a substring
      $params = explode(":", $rule->type);
      $btx[$rule->to] = str_replace($params[1], $params[2], $value);
    } elseif ($this->startsWith($rule->type, 'format')) {
      // will use the sprintf format
      $params = explode(":", $rule->type);
      $btx[$rule->to] = sprintf($params[1], $value);
    } elseif ($this->startsWith($rule->type, 'constant')) {
      // will just set a constant string
      $btx[$rule->to] = $rule->from;
    } elseif ($this->startsWith($rule->type, 'strtotime')) {
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
    } elseif ($this->startsWith($rule->type, 'amount')) {
      // AMOUNT will take care of currency issues, like "," instead of "."
      $btx[$rule->to] = str_replace(",", ".", $value);
    } elseif ($this->startsWith($rule->type, 'regex:')) {
      // REGEX will extract certain values from the line
      $pattern = substr($rule->type, 6);
      $matches = array();
      if (preg_match($pattern, $value, $matches)) {
        // we found it!
        if (isset($matches[$rule->to])) {
          $btx[$rule->to] = $matches[$rule->to];
        } else {
          $btx[$rule->to] = $matches[1];
        }
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
  /**
   * helper function for prefix testing
   */
  function startsWith($string, $prefix) {
    return substr($string, 0, strlen($prefix)) === $prefix;
  }
}
