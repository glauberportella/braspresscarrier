<?php
$sql[] = 'SET foreign_key_checks=0;';
$sql[] = 'DROP TABLE `'._DB_PREFIX_.'braspress_regiao`;';
$sql[] = 'DROP TABLE `'._DB_PREFIX_.'braspress_faixa_peso`;';
$sql[] = 'DROP TABLE `'._DB_PREFIX_.'braspress_faixa_peso`;';
$sql[] = 'DROP TABLE `'._DB_PREFIX_.'braspress_taxas_frete`;';
$sql[] = 'DROP TABLE `'._DB_PREFIX_.'braspress_trf_regiao`;';
$sql[] = 'DROP TABLE `'._DB_PREFIX_.'braspress_tas_rodo_regiao`;';
$sql[] = 'DROP TABLE `'._DB_PREFIX_.'braspress_suframa_regiao`;';
$sql[] = 'SET foreign_key_checks=1;';
?>