<?php
session_start();
if (!$_SESSION['session_name']) {
    header("location:login.html");
}

include '../vendor/server/php/ChromePhp.php';
ChromePhp::log(json_encode($_POST));


$userID = $_SESSION['user_id'];
$username = $_SESSION['session_name'];

$paramFieldNameMap = array (
    "paramMissedCleavagesValue" => array ("required" => true, "validate" => FILTER_VALIDATE_INT),
    "paramToleranceValue" => array ("required" => true, "validate" => FILTER_VALIDATE_INT),
    "paramToleranceUnits" => array ("required" => true),
    "paramTolerance2Value" => array ("required" => true, "validate" => FILTER_VALIDATE_INT),
    "paramTolerance2Units" => array ("required" => true),
    "paramEnzymeSelect" => array ("required" => true, "validate" => FILTER_VALIDATE_INT),
    "paramNotes" => array ("required" => false),
    "acqPreviousTable" => array ("required" => true, "validate" => FILTER_VALIDATE_INT),
    "seqPreviousTable" => array ("required" => true, "validate" => FILTER_VALIDATE_INT)
);

$paramLinkTableMap = array (
    "paramCrossLinkerSelect" => array ("required" => true),
    "paramFixedModsSelect" => array ("required" => false, "defaults" => array ("fixed" => "true"), "validate" => FILTER_VALIDATE_INT),
    "paramVarModsSelect" => array ("required" => false, "defaults" => array ("fixed" => "false"), "validate" => FILTER_VALIDATE_INT),
    "paramIonsSelect" => array ("required" => true, "validate" => FILTER_VALIDATE_INT),
    "paramLossesSelect" => array ("required" => false, "validate" => FILTER_VALIDATE_INT),
);


$allUserFieldsMap = array_merge ($paramFieldNameMap, $paramLinkTableMap);


// Check everything necessary is in the bag
$allGood = true;

foreach ($allUserFieldsMap as $key => $value) {
    // if no post value for expected variable give it a blank string
    if (!isset($_POST[$key])) {
        //ChromePhp::log(json_encode("missing ".$key));
        $_POST[$key] = array_key_exists ($key, $paramLinkTableMap) ? [] : null;
        if ($value["required"]) {
            $allGood = false;
        }
    }
    else {
        $arrval = $_POST[$key];
        $count = count($arrval);
        if ($value["required"] == true && ($count == 0 || ($count == 1 && strlen($arrval[0]) == 0))) {
            $allGood = false;
        }

        else if (array_key_exists ("validate", $value)) {
            if (!is_array ($arrval)) {
                $arrval = array($arrval);   // single item array
            }
            foreach ($arrval as $index => $val) {
                $valid = filter_var ($val, $value["validate"]);
                //ChromePhp::log(json_encode(array($val, $valid)));
                if ($valid === false) {
                    $allGood = false;
                }
            }  
        }
    }
}


//ChromePhp::log(json_encode($allGood));


if ($allGood) {
    
    // make timestamps to use in name fields and in timestamp fields (different format required)
    $date = new DateTime();
    $SQLValidTimeStamp = $date->format("Y-m-d H:i:s");
    //ChromePhp::log(json_encode($SQLValidTimeStamp));
    $timeStamp = $date->format("H_i_s-d_M_Y");
    
    $preparedStatementTexts = array (
        "paramSet" => "INSERT INTO parameter_set (enzyme_chosen, name, uploadedby, missed_cleavages, ms_tol, ms2_tol, ms_tol_unit, ms2_tol_unit, upload_date, notes, top_alpha_matches, template, synthetic) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, '10', FALSE, FALSE)",
        "paramFixedModsSelect" => "INSERT INTO chosen_modification (paramset_id, mod_id, fixed) VALUES ($1, $2, $3)",
        "paramVarModsSelect" => "INSERT INTO chosen_modification (paramset_id, mod_id, fixed) VALUES ($1, $2, $3)",
        "paramIonsSelect" => "INSERT INTO chosen_ions (paramset_id, ion_id) VALUES ($1, $2)",
        "paramLossesSelect" => "INSERT INTO chosen_losses (paramset_id, loss_id) VALUES ($1, $2)",
        "paramCrossLinkerSelect" => "INSERT INTO chosen_crosslinker (paramset_id, crosslinker_id) VALUES ($1, $2)",
        "acqPreviousTable" => "SELECT name FROM acquisition WHERE id = ANY ($1::int[])",
        "getUserGroups" => "SELECT group_id FROM user_in_group WHERE user_id = $1",
        "newSearch" => "INSERT INTO search (paramset_id, visible_group, name, uploadedby, submit_date, notes, status, completed, is_executing) VALUES ($1, $2, $3, $4, $5, $6, 'queueing', FALSE, FALSE) RETURNING id",
        "newSearchSeqLink" => "INSERT INTO search_sequencedb (search_id, seqdb_id) VALUES($1, $2)",
        "getRuns" => "SELECT acq_id, run_id FROM run WHERE acq_id = ANY($1::int[])",
        "newSearchAcqLink" => "INSERT INTO search_acquisition (search_id, acq_id, run_id) VALUES($1, $2, $3)"
    );

    
    include('../../connectionStringSafe.php');
    //open connection
    $dbconn = pg_connect($connectionString) or die('Could not connect: ' . pg_last_error());
    
    try {
        pg_query("BEGIN") or die("Could not start transaction\n");
        
        // little bobby tables - https://xkcd.com/327/

        // Get names of acquisitions (via ids) to make parameter name
        $acqIds = "{".join(',',$_POST["acqPreviousTable"])."}"; // re-use this later to get run data too
        //ChromePhp::log(json_encode($acqIds));
        $getAcqNames = pg_prepare($dbconn, "getAcqNames", $preparedStatementTexts["acqPreviousTable"]);
        $result = pg_execute($dbconn, "getAcqNames", array($acqIds));
        $acqNameRows = pg_fetch_all ($result); // get associated acquisition names to make name for parameter
        $allAcqNames = array_map(function($row) { return $row["name"]; }, $acqNameRows);
        $paramName = join('-',$allAcqNames)."-".$timeStamp;
        //ChromePhp::log(json_encode($paramName));
        
        // Add parameter_set values to db
        $result = pg_prepare($dbconn, "paramsAdd", $preparedStatementTexts["paramSet"]);
        $result = pg_execute($dbconn, "paramsAdd", [$_POST["paramEnzymeSelect"], $paramName, $userID, $_POST["paramMissedCleavagesValue"], $_POST["paramToleranceValue"],
                                                   $_POST["paramTolerance2Value"], $_POST["paramToleranceUnits"], $_POST["paramTolerance2Units"], 
                                                    $SQLValidTimeStamp, $_POST["paramNotes"]]);
        $paramIDGet = pg_prepare($dbconn, "paramIDGet", "SELECT id, name from parameter_set where uploadedby = $1 AND name = $2 AND upload_date = $3");
        $result =  pg_execute($dbconn, "paramIDGet", [$userID, $paramName, $SQLValidTimeStamp]);
        $paramIdRow = pg_fetch_assoc ($result); // get the newly added row, need it to add runs here and to return to client ui
        $paramid = $paramIdRow["id"];
        
        // Add link tables to connect parameter_set to ions/mods/losses/crosslinkers
        foreach ($paramLinkTableMap as $key => $value) {
            
            $arrval = $_POST[$key];
            if (!is_array ($arrval)) {
                $arrval = array($arrval);   // single item array
            }
            
            $result = pg_prepare ($dbconn, $key, $preparedStatementTexts[$key]);
            // change to multi-insert later? http://php.net/manual/en/mysqli.quickstart.prepared-statements.php
            foreach ($arrval as $mval) {
                $arr = [$paramid, $mval];
                if (array_key_exists ("defaults", $value)) {
                    foreach ($value["defaults"] as $dkey => $dval) {
                        $arr[] = $dval; // append $dval to $arr
                    }
                } 
                //ChromePhp::log(json_encode($arr));
                $result = pg_execute($dbconn, $key, $arr);
            }
        }
        
        $getUserGroups = pg_prepare ($dbconn, "getUserGroups", $preparedStatementTexts["getUserGroups"]);
        $result = pg_execute ($dbconn, "getUserGroups", [$userID]);
        $userGroupIdRow = pg_fetch_assoc ($result); // get first user group for this user
        $userGroupId = $userGroupIdRow["group_id"];
        //ChromePhp::log(json_encode($userGroupId));
        
        // Add search to db
        $searchName = $paramName;   // They appear to be the same construct
        $searchInsert = pg_prepare ($dbconn, "searchInsert", $preparedStatementTexts["newSearch"]);
        $result = pg_execute ($dbconn, "searchInsert", [$paramid, $userGroupId, $searchName, $userID, $SQLValidTimeStamp, $_POST["paramNotes"]]);
        $searchRow = pg_fetch_assoc ($result); // get the newly added search id
        $searchid = $searchRow["id"];
        
        // Add search-to-sequence link table rows
        $searchSeqLink = pg_prepare ($dbconn, "searchSeqLink", $preparedStatementTexts["newSearchSeqLink"]);
        foreach ($_POST["seqPreviousTable"] as $key => $seqid) {
            $result = pg_execute ($dbconn, "searchSeqLink", [$searchid, $seqid]);
        }
        
        // Get run info
        $getRuns = pg_prepare ($dbconn, "getRuns", $preparedStatementTexts["getRuns"]);
        $result = pg_execute ($dbconn, "getRuns", [$acqIds]);
        $runRows = pg_fetch_all ($result);
        //ChromePhp::log(json_encode($runRows));
        // Add search-to-acquisition-and-run link table rows
        $searchAcqLink = pg_prepare ($dbconn, "searchAcqLink", $preparedStatementTexts["newSearchAcqLink"]);
        foreach ($runRows as $key => $run) {
            $result = pg_execute ($dbconn, "searchAcqLink", [$searchid, $run["acq_id"], $run["run_id"]]);
        }
        
        pg_query("COMMIT");

        echo (json_encode(array ("status"=>"success", "newSearch"=>$searchRow)));
    } catch (Exception $e) {
        pg_query("ROLLBACK");
        echo (json_encode(array ("status"=>"fail", "error"=>$e)));
    }
    
    //close connection
    pg_close($dbconn);
}

else {
    echo (json_encode(array ("status"=>"fail", "error"=>"missing or invalid fields")));
}

?>