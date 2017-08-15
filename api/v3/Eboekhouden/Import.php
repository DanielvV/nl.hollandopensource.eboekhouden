<?php

/**
 * Eboekhouden.Import API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_eboekhouden_Import($params) {

  $result = civicrm_api3('BankingPluginInstance', 'getsingle', array(
    'return' => array("id"),
    'name' => "E-boekhouden Importer",
  ));

  $plugin_id = $result["id"];
  $import_parameters = array( 'dry_run' => ("off"),
                              'source' => ('stream'),
                              );
  $plugin_list = CRM_Banking_BAO_PluginInstance::listInstances('import');
  foreach ($plugin_list as $plugin) {
    if ($plugin->id == $plugin_id) {
      $plugin_instance = $plugin->getInstance();
      $plugin_instance->import_stream($import_parameters);
      break;
    } 
  }

  $result = civicrm_api3('BankingTransactionBatch', 'get', array(
    'return' => ["id"],
  ));
  $result = civicrm_api3('BankingTransaction', 'analyselist', array(
    's_list' => end($result["values"])["id"],
  ));

  $returnValues = $result;

  // Spec: civicrm_api3_create_success($values = 1, $params = array(), $entity = NULL, $action = NULL)
  return civicrm_api3_create_success($returnValues, $params, 'Eboekhouden', 'Import');
}
