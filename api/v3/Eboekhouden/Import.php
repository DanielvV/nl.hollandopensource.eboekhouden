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

  $client = new SoapClient("https://soap.e-boekhouden.nl/soap.asmx?WSDL");
  $Username = civicrm_api3('Setting', 'getvalue', array(
    'name' => "eboekhouden_username",
  ));
  $SecurityCode1 = civicrm_api3('Setting', 'getvalue', array(
    'name' => "eboekhouden_code1",
  ));
  $SecurityCode2 = civicrm_api3('Setting', 'getvalue', array(
    'name' => "eboekhouden_code2",
  ));

  // sessie openen en sessionid ophalen
  $parameters = array(
    "Username" => $Username,
    "SecurityCode1" => $SecurityCode1,
    "SecurityCode2" => $SecurityCode2
  );

  $response = $client->__soapCall("OpenSession", array($parameters));
  $SessionID = $response->OpenSessionResult->SessionID;

  // opvragen alle mutaties
  $parameters = array(
    "SecurityCode2" => $SecurityCode2,
    "SessionID" => $SessionID,
    "cFilter" => array(
      "MutatieNr" => 0,
      "Factuurnummer" => "",
      "DatumVan" => "2017-03-01",
      "DatumTm" => "2017-07-07"
    )
  );
  $response = $client->__soapCall("GetMutaties", array($parameters));
//  print_r($response);
  $Mutaties = $response->GetMutatiesResult->Mutaties;

  // indien een resultaat, dan even een array maken
  if(!is_array($Mutaties->cMutatieList))
    $Mutaties->cMutatieList = array($Mutaties->cMutatieList);

  foreach ($Mutaties->cMutatieList as $Mutatie) {
    preg_match('/[A-Z]{2}[0-9]{2}[A-Z0-9]*\b/', $Mutatie->Omschrijving, $_party_IBAN);
    preg_match('/\b[A-Z]{6}[A-Z0-9]{2}\b/', $Mutatie->Omschrijving, $_party_BIC);
    preg_match('/\b[0-9]{8}([0-9]{7})[0-9]{1}\b/', $Mutatie->Omschrijving, $contactnummer);

    $result = civicrm_api3('BankingTransaction', 'create', [

      'bank_reference' => $Mutatie->MutatieNr,

      'value_date' => $Mutatie->Datum,

      'booking_date' => $Mutatie->Datum,

      'amount' => $Mutatie->MutatieRegels->cMutatieListRegel->BedragInvoer,
      'currency' => "EUR",
      'type_id' => "0",
      'status_id' => 889,
      'data_raw' => $Mutatie->Omschrijving,
      'data_parsed' => "{\"contactnummer\":\"".ltrim($contactnummer[1],0)."\",\"payment_instrument_id\":\"5\",\"financial_type_id\":\"1\",\"purpose\":\"{$Mutatie->MutatieRegels->cMutatieListRegel->TegenrekeningCode}\",\"_party_IBAN\":\"{$_party_IBAN[0]}\",\"_party_BIC\":\"{$_party_BIC[0]}\"}",
      'tx_batch_id' => 1,
      'suggestions' => "[{\"probability\":1.0,\"reasons\":[],\"title\":\"Manuallyprocessed\",\"plugin_id\":\"3\",\"id\":\"manual\",\"contact_ids\":\"".ltrim($contactnummer[1],0)."\",\"contact_ids2probablility\":\"[]\"}]"
    ]);
  }

  // sessie sluiten
  $parameters = array(
    "SessionID" => $SessionID
  );
  $response = $client->__soapCall("CloseSession", array($parameters));


  $returnValues = array();

  // Spec: civicrm_api3_create_success($values = 1, $params = array(), $entity = NULL, $action = NULL)
  return civicrm_api3_create_success($returnValues, $params, 'NewEntity', 'NewAction');
}
