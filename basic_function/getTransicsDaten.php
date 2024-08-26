<?php
    require "telematik/telematik_kundendaten.php";
    $transics_object = new telematik_kundendaten();
    $transics_object->setTelematicTypeIdx(6);
    $transics_object->setDebugModus('d');
    $transics_object->getKundendaten();
?>