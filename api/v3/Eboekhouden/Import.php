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

//disabled$client = new SoapClient("https://soap.e-boekhouden.nl/soap.asmx?WSDL");
//disabled$Username = civicrm_api3('Setting', 'getvalue', array(
//disabled//disabled'name' => "eboekhouden_username",
//disabled));
//disabled$SecurityCode1 = civicrm_api3('Setting', 'getvalue', array(
//disabled//disabled'name' => "eboekhouden_code1",
//disabled));
//disabled$SecurityCode2 = civicrm_api3('Setting', 'getvalue', array(
//disabled//disabled'name' => "eboekhouden_code2",
//disabled));

//disabled// sessie openen en sessionid ophalen
//disabled$parameters = array(
//disabled//disabled"Username" => $Username,
//disabled//disabled"SecurityCode1" => $SecurityCode1,
//disabled//disabled"SecurityCode2" => $SecurityCode2
//disabled);

//disabled$response = $client->__soapCall("OpenSession", array($parameters));
//disabled$SessionID = $response->OpenSessionResult->SessionID;

//disabled// opvragen alle mutaties
//disabled$parameters = array(
//disabled//disabled"SecurityCode2" => $SecurityCode2,
//disabled//disabled"SessionID" => $SessionID,
//disabled//disabled"cFilter" => array(
//disabled//disabled//disabled"MutatieNr" => 0,
//disabled//disabled//disabled"Factuurnummer" => "",
//disabled//disabled//disabled"DatumVan" => "2017-03-01",
//disabled//disabled//disabled"DatumTm" => "2017-07-07"
//disabled//disabled)
//disabled);
//disabled$response = $client->__soapCall("GetMutaties", array($parameters));
////disabledprint_r($response);
//disabled$Mutaties = $response->GetMutatiesResult->Mutaties;

//disabled// indien een resultaat, dan even een array maken
//disabledif(!is_array($Mutaties->cMutatieList))
//disabled//disabled$Mutaties->cMutatieList = array($Mutaties->cMutatieList);

//disabledforeach ($Mutaties->cMutatieList as $Mutatie) {
//disabled//disabledpreg_match('/[A-Z]{2}[0-9]{2}[A-Z0-9]*\b/', $Mutatie->Omschrijving, $_party_IBAN);
//disabled//disabledpreg_match('/\b[A-Z]{6}[A-Z0-9]{2}\b/', $Mutatie->Omschrijving, $_party_BIC);
//disabled//disabledpreg_match('/\b[0-9]{8}([0-9]{7})[0-9]{1}\b/', $Mutatie->Omschrijving, $contactnummer);

//disabled//disabled$result = civicrm_api3('BankingTransaction', 'create', [

//disabled//disabled//disabled'bank_reference' => $Mutatie->MutatieNr,

//disabled//disabled//disabled'value_date' => $Mutatie->Datum,

//disabled//disabled//disabled'booking_date' => $Mutatie->Datum,

//disabled//disabled//disabled'amount' => $Mutatie->MutatieRegels->cMutatieListRegel->BedragInvoer,
//disabled//disabled//disabled'currency' => "EUR",
//disabled//disabled//disabled'type_id' => "0",
//disabled//disabled//disabled'status_id' => 889,
//disabled//disabled//disabled'data_raw' => $Mutatie->Omschrijving,
//disabled//disabled//disabled'data_parsed' => "{\"contactnummer\":\"".ltrim($contactnummer[1],0)."\",\"payment_instrument_id\":\"5\",\"financial_type_id\":\"1\",\"purpose\":\"{$Mutatie->MutatieRegels->cMutatieListRegel->TegenrekeningCode}\",\"_party_IBAN\":\"{$_party_IBAN[0]}\",\"_party_BIC\":\"{$_party_BIC[0]}\"}",
//disabled//disabled//disabled'tx_batch_id' => 1,
//disabled//disabled//disabled'suggestions' => "[{\"probability\":1.0,\"reasons\":[],\"title\":\"E-boekhouden import\",\"plugin_id\":\"3\",\"id\":\"eboekhouden\",\"contact_ids\":\"".ltrim($contactnummer[1],0)."\",\"contact_ids2probablility\":\"[]\"}]"
//disabled//disabled]);
//disabled}

//disabled// sessie sluiten
//disabled$parameters = array(
//disabled//disabled"SessionID" => $SessionID
//disabled);
//disabled$response = $client->__soapCall("CloseSession", array($parameters));


//disabled$returnValues = array();

//disabled// Spec: civicrm_api3_create_success($values = 1, $params = array(), $entity = NULL, $action = NULL)
//disabledreturn civicrm_api3_create_success($returnValues, $params, 'NewEntity', 'NewAction');
}
