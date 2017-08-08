<?php
// This file declares a managed database record of type "Job".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return array (
  0 => 
  array (
    'name' => 'Cron:Eboekhouden.Import',
    'entity' => 'Job',
    'params' => 
    array (
      'version' => 3,
      'name' => 'Call Eboekhouden.Import API',
      'description' => 'Call Eboekhouden.Import API',
      'run_frequency' => 'Hourly',
      'api_entity' => 'Eboekhouden',
      'api_action' => 'Import',
      'parameters' => '',
    ),
  ),
);
