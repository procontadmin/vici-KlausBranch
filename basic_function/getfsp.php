<?php

$connection = ssh2_connect('116.202.251.183');

if (!ssh2_auth_password($connection, 'procont', 'PCS!root42')) {
    throw new Exception('Impossible de ce connencter.');
}
if (!$sftp = ssh2_sftp($connection)) {
    throw new Exception('Impossible de ce connencter.');
}

$dirhandle = opendir("ssh2.sftp://$sftp/ddd_files/1/web");

while (false !== ($folder = readdir($dirhandle))) {
    if (($folder != '.') && ($folder != '..')) {
        $structure = "C:/dddfiles/transics/Web/$folder/";
        if (!is_dir($structure)) {
            mkdir($structure);
        }

        $dirhandle2 = opendir("ssh2.sftp://$sftp/ddd_files/1/web/$folder/");
        while (false !== ($file = readdir($dirhandle2))) {
            if (($file != '.') && ($file != '..') && substr($file, -3) == "fsp") {
		$contents = file_get_contents("ssh2.sftp://$sftp/ddd_files/1/web/$folder/$file");
                file_put_contents("$structure/$file", $contents);
                 $dbNew = new mysqli("localhost", "pcsdev", "MnL6E{=B7f,@8Ju!", "difa_resources", 3306);
           	 mysqli_set_charset($dbNew,"utf8mb4");

            	$getStm = "SELECT idx FROM difa_resources.difa_nfc_tags WHERE serial_number = '$sNumber';";

            	$res = $dbNew->query($getStm);
            	var_dump($res);
            	while ($row = $res->fetch_assoc())
            	{
              	  print "!!!";
              	  var_dump($row);
              	  if (!empty($row))
               	  {
                    $putStm = "INSERT IGNORE INTO difa_resources.driver_licence_control (`nfc_tag_idx`, `control_date`) VALUES($row[0]['idx'], '$date->format('Y-m-d H:i:s')')";
                    $dbNew->query($putStm);
                    fclose($filePath);
                    unlink($filePath);
               	 }
               	 else
                 {
                    fclose($filePath);
                 }
            	}
            }
        }
    }
}