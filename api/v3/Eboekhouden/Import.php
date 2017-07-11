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

  // sessie openen en sessionid ophalen
  $params = array(
    "Username" => $Username,
    "SecurityCode1" => $SecurityCode1,
    "SecurityCode2" => $SecurityCode2
  );

  $response = $client->__soapCall("OpenSession", array($params));
  $SessionID = $response->OpenSessionResult->SessionID;

  echo "SessionID: " . $SessionID;
  echo "<hr>";

  // opvragen alle mutaties
  $params = array(
    "SecurityCode2" => $SecurityCode2,
    "SessionID" => $SessionID,
    "cFilter" => array(
      "MutatieNr" => 0,
      "Factuurnummer" => "",
      "DatumVan" => "2017-03-01",
      "DatumTm" => "2017-07-07"
    )
  );
  $response = $client->__soapCall("GetMutaties", array($params));
//  print_r($response);
  $Mutaties = $response->GetMutatiesResult->Mutaties;

  // indien een resultaat, dan even een array maken
  if(!is_array($Mutaties->cMutatieList))
    $Mutaties->cMutatieList = array($Mutaties->cMutatieList);

  foreach ($Mutaties->cMutatieList as $Mutatie) {
    $result = civicrm_api3('BankingTransaction', 'create', [
      'bank_reference' => $Mutatie->MutatieNr,
      'value_date' => $Mutatie->Datum,
      'booking_date' => $Mutatie->Datum,
      'status_id' => 889,
      'tx_batch_id' => 1,
      'amount' => $Mutatie->MutatieRegels->cMutatieListRegel->BedragInvoer,
    ]);
    echo '<tr>';
    echo '<td>' . $Mutatie->Soort . '</td>';
    echo '<td>' . $Mutatie->Rekening . '</td>';
    echo '<td>' . $Mutatie->RelatieCode . '</td>';
    echo '<td>' . $Mutatie->Factuurnummer . '</td>';
    echo '<td>' . $Mutatie->Boekstuk . '</td>';
    echo '<td>' . $Mutatie->Omschrijving . '</td>';
    echo '<td>' . $Mutatie->Betalingstermijn . '</td>';
    echo '<td>' . $Mutatie->InExBTW . '</td>';
    echo '<td>' . $Mutatie->MutatieRegels->cMutatieListRegel->BedragExclBTW . '</td>';
    echo '<td>' . $Mutatie->MutatieRegels->cMutatieListRegel->Factuurnummer . '</td>';
    echo '<td>' . $Mutatie->MutatieRegels->cMutatieListRegel->TegenrekeningCode . '</td>';
    echo '<td>' . $Mutatie->MutatieRegels->cMutatieListRegel->KostenplaatsID . '</td>';
    echo '</tr>';
  }
  echo '</table>';

  // sessie sluiten
  $params = array(
    "SessionID" => $SessionID
  );
  $response = $client->__soapCall("CloseSession", array($params));


  if (array_key_exists('magicword', $params) && $params['magicword'] == 'sesame') {
    $returnValues = array(
      // OK, return several data rows
      12 => array('id' => 12, 'name' => 'Twelve'),
      34 => array('id' => 34, 'name' => 'Thirty four'),
      56 => array('id' => 56, 'name' => 'Fifty six'),
    );
    // ALTERNATIVE: $returnValues = array(); // OK, success
    // ALTERNATIVE: $returnValues = array("Some value"); // OK, return a single value

    // Spec: civicrm_api3_create_success($values = 1, $params = array(), $entity = NULL, $action = NULL)
    return civicrm_api3_create_success($returnValues, $params, 'NewEntity', 'NewAction');
  }
  else {
    throw new API_Exception(/*errorMessage*/ 'Everyone knows that the magicword is "sesame"', /*errorCode*/ 1234);
  }
}
