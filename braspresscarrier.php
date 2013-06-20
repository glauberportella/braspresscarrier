<?php
// Avoid direct access to the file
if (!defined('_PS_VERSION_'))
	exit;

class BraspressCarrier extends CarrierModule
{
	public $id_carrier;

	private $_html = '';
	private $_postErrors = array();
	private $_moduleName = 'braspresscarrier';

	public function __construct()
	{
		$this->name = 'braspresscarrier';
		$this->tab = 'shipping_logistics';
		$this->version = '1.00';
		$this->author = 'Glauber Portella';
		$this->limited_countries = array('br');

		parent::__construct ();

		$this->displayName = $this->l('Braspress Carrier');
		$this->description = $this->l('Modulo para transportadora Braspress nível Brasil');

		if (self::isInstalled($this->name))
		{
			// Getting carrier list
			global $cookie;
			$carriers = Carrier::getCarriers($cookie->id_lang, true, false, false, NULL, PS_CARRIERS_AND_CARRIER_MODULES_NEED_RANGE);

			// Saving id carrier list
			$id_carrier_list = array();
			foreach($carriers as $carrier)
				$id_carrier_list[] .= $carrier['id_carrier'];

			// Testing if Carrier Id exists
			$warning = array();
			if (!in_array((int)(Configuration::get('BRASPRESS_CARRIER_ID')), $id_carrier_list))
				$warning[] .= $this->l('"BRASPRESS"').' ';

			if (count($warning))
				$this->warning .= implode(' , ',$warning).$this->l('must be configured to use this module correctly').' ';
		}
	}

	public function install()
	{
		/**
		 * Criar dados Iniciais da Braspress
		 * 1 - Executar sql/braspress-create.sql
		 * 2 - Criar transportadora Braspress
		 * 3 - Salvar ID da transportadora Braspress criada
		 */
		if (!$this->createSchema())
			return false;

		// criamos a transportadora braspress
		$carrier = array(
				'name' => 'BRASPRESS',
				'id_tax_rules_group' => 0, // We do not apply thecarriers tax
				'active' => true,
				'deleted' => 0,
				'shipping_handling' => false,
				'range_behavior' => 0,
				'delay' => array(
					'en' => 'Descrição da entrega',
					'br' => 'Descrição da entrega',
					Language::getIsoById(Configuration::get
						('PS_LANG_DEFAULT')) => 'Descrição de entrega'),
				'id_zone' => 6, // Area where the carrier operates
				'is_module' => true, // We specify that it is a module
				'shipping_external' => true,
				'external_module_name' => 'braspresscarrier', // We specify the name of the module
				'need_range' => false // We specify that we do not want the calculations for the ranges
					// that are configured in the back office
			);
		$id_carrier = $this->installExternalCarrier($carrier);


		if (!parent::install() ||
			!Configuration::updateValue('BRASPRESS_CARRIER_ID', (int)$id_carrier) ||
			!$this->registerHook('updateCarrier'))
			return false;

		return true;
	}

	public function uninstall()
	{
		// We first carry out a classic uninstall of a module
		if (!parent::uninstall() ||
			!$this->dropSchema() ||
			!$this->unregisterHook('updateCarrier'))
			return false;

		// We delete the carriers we created earlier
		$carrier = new Carrier((int)(Configuration::get('BRASPRESS_CARRIER_ID')));
		// If one the modules was the default carrier,
		// we choose another
		if (Configuration::get('PS_CARRIER_DEFAULT') == (int)($carrier->id))
		{
			global $cookie;
			$carriersD = Carrier::getCarriers($cookie->id_lang, true, false, false, NULL, PS_CARRIERS_AND_CARRIER_MODULES_NEED_RANGE);
			foreach($carriersD as $carrierD)
				if ($carrierD['active'] AND !$carrierD['deleted']
					AND ($carrierD['name'] != $this->_config['name']))
					Configuration::updateValue('PS_CARRIER_DEFAULT', $carrierD['id_carrier']);
		}

		// Then we delete the carriers using variable delete
		// in order to keep the carrier history for orders placed with them
		$carrier->deleted = 1;
		if (!$carrier->update())
			return false;

		return true;
	}

	/*
	** Hook update carrier
	**
	*/
	public function hookupdateCarrier($params)
	{
		// Update the id for carrier
		if ((int)($params['id_carrier']) == (int)(Configuration::get('BRASPRESS_CARRIER_ID')))
			Configuration::updateValue('BRASPRESS_CARRIER_ID', (int)($params['carrier']->id));
	}

	public function getContent()
	{
		$this->_html .= '<h2>' . $this->l('Braspress Carrier').'</h2>';
		if (!empty($_POST) AND Tools::isSubmit('submitSave'))
		{
			$this->_postValidation();
			if (!sizeof($this->_postErrors))
				$this->_postProcess();
			else
				foreach ($this->_postErrors AS $err)
					$this->_html .= '<div class="alert error"><img src="'._PS_IMG_.'admin/forbbiden.gif" alt="nok" />&nbsp;'.$err.'</div>';
		}
		$this->_displayForm();
		return $this->_html;
	}

	private function _displayForm()
	{
		$db = Db::getInstance();

		$regioesSql = 'SELECT * FROM '._DB_PREFIX_.'braspress_regiao';
		$regioes = array();
		while ($regiao = $db->getRow($regioesSql)) {
			$regioes[$regiao['id']] = $regiao;
		}
		$pesosSql = 'SELECT * FROM '._DB_PREFIX_.'braspress_faixa_peso';
		$faixasPeso = array();
		while ($faixa = $db->getRow($pesosSql)) {
			$faixasPeso[$faixa['id']] = $faixa;
		}
		$taxasSql = 'SELECT * FROM '._DB_PREFIX_.'braspress_taxas_frete WHERE braspress_regiao_id = %d AND braspress_faixa_peso_id = %d';
		$taxas = array();
		foreach ($regioes as $id_regiao => $regiao) {
			foreach ($faixasPeso as $id_faixa_peso => $faixa) {
				$prepared = sprintf($taxasSql, $id_regiao, $id_faixa_peso);
				if ($taxa = $db->getRow($prepared)) {
					$id = sprintf('%d_%d', $id_regiao, $id_faixa_peso);
					$taxas[$id] = $taxa;
				}
			}
		}
		// monta html da tabela para entrada das taxas
		$tableRows = array();
		foreach ($regioes as $id_regiao => $regiao) {
			$tr = '<tr>';
			$columns = array();

			foreach ($faixasPeso as $id_faixa_peso => $faixa) {
				$taxa_id = sprintf('%d_%d', $id_regiao, $id_taxa);
				$taxa = $taxas[$taxa_id];
				$fp = sprintf('<input type="text" class="braspress_taxa_input" name="regiao[%d][fp]" value="%f" />', (int)$id_regiao, (float)$taxa['fp']);
				$fv = sprintf('<input type="text" class="braspress_taxa_input" name="regioa[%d][fv]" value="%f" />', (int)$id_regiao, (float)$taxa['fv']);
				$columns[] = sprintf('<td>%s<br />%s</td>', $fp, $fv);
			}

			$tr .= implode(' ', $columns);
			$tr .= '</tr>';
			$tableRows[] = $tr;
		}
		$table = '<table width="100%" cellpadding="4" cellspacing="0" bordercolor="#000">';
		$table .= implode(' ', $tableRows);
		$table .= '</table>';

		$this->_html .= '
			<form action="index.php?tab='.Tools::getValue('tab').'&configure='.Tools::getValue('configure').'&token='.Tools::getValue('token').'&tab_module='.Tools::getValue('tab_module').'&module_name='.Tools::getValue('module_name').'&id_tab=1&section=general" method="post" class="form" id="configForm">
				<fieldset>
					<legend><img src="'.$this->_path.'logo.gif" alt="" /> '.$this->l('Taxas Braspress Nível Nacional').'</legend>
					<h4>'.$this->l('BRASPRESS Taxas Nível Nacional').' :</h4>
					'.$table.'
					<div class="margin-form"><input class="button" name="submitSave" type="submit"></div>
				</fieldset>
			</form>';
	}

	private function _postValidation()
	{
		// Check configuration values
		$this->_postErrors[]  = $this->l('Configure todas as taxas conforme sua tabela Braspress');
	}

	private function _postProcess()
	{
		// Saving new configurations
		if (/* UPDATE BD values */true)
			$this->_html .= $this->displayConfirmation($this->l('Settings updated'));
		else
			$this->_html .= $this->displayErrors($this->l('Settings failed'));
	}

	public static function installExternalCarrier($config)
	{
		$carrier = new Carrier();
		$carrier->name = $config['name'];
		$carrier->id_tax_rules_group = $config['id_tax_rules_group'];
		$carrier->id_zone = $config['id_zone'];
		$carrier->active = $config['active'];
		$carrier->deleted = $config['deleted'];
		$carrier->delay = $config['delay'];
		$carrier->shipping_handling = $config['shipping_handling'];
		$carrier->range_behavior = $config['range_behavior'];
		$carrier->is_module = $config['is_module'];
		$carrier->shipping_external = $config['shipping_external'];
		$carrier->external_module_name = $config['external_module_name'];
		$carrier->need_range = $config['need_range'];

		$languages = Language::getLanguages(true);
		foreach ($languages as $language)
		{
			if ($language['iso_code'] == 'br')
				$carrier->delay[(int)$language['id_lang']] = $config['delay'][$language['iso_code']];
			if ($language['iso_code'] == 'en')
				$carrier->delay[(int)$language['id_lang']] = $config['delay'][$language['iso_code']];
			if ($language['iso_code'] == Language::getIsoById(Configuration::get('PS_LANG_DEFAULT')))
				$carrier->delay[(int)$language['id_lang']] = $config['delay'][$language['iso_code']];
		}

		if ($carrier->add())
		{
			$groups = Group::getGroups(true);
			foreach ($groups as $group)
				Db::getInstance()->autoExecute(_DB_PREFIX_.'carrier_group', array('id_carrier' => (int)($carrier->id), 'id_group' => (int)($group['id_group'])), 'INSERT');

			$rangePrice = new RangePrice();
			$rangePrice->id_carrier = $carrier->id;
			$rangePrice->delimiter1 = '0';
			$rangePrice->delimiter2 = '1000000';
			$rangePrice->add();

			$rangeWeight = new RangeWeight();
			$rangeWeight->id_carrier = $carrier->id;
			$rangeWeight->delimiter1 = '0';
			$rangeWeight->delimiter2 = '10000';
			$rangeWeight->add();

			$zones = Zone::getZones(true);
			foreach ($zones as $zone)
			{
				Db::getInstance()->autoExecute(_DB_PREFIX_.'carrier_zone', array('id_carrier' => (int)($carrier->id), 'id_zone' => (int)($zone['id_zone'])), 'INSERT');
				Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_.'delivery', array('id_carrier' => (int)($carrier->id), 'id_range_price' => (int)($rangePrice->id), 'id_range_weight' => NULL, 'id_zone' => (int)($zone['id_zone']), 'price' => '0'), 'INSERT');
				Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_.'delivery', array('id_carrier' => (int)($carrier->id), 'id_range_price' => NULL, 'id_range_weight' => (int)($rangeWeight->id), 'id_zone' => (int)($zone['id_zone']), 'price' => '0'), 'INSERT');
			}

			// Copy Logo
			if (!copy(dirname(__FILE__).'/braspress.jpg', _PS_SHIP_IMG_DIR_.'/'.(int)$carrier->id.'.jpg'))
				return false;

			// Return ID Carrier
			return (int)($carrier->id);
		}

		return false;
	}

	/*
	** Front Methods
	**
	** If you set need_range at true when you created your carrier (in install method), the method called by the cart will be getOrderShippingCost
	** If not, the method called will be getOrderShippingCostExternal
	**
	** $params var contains the cart, the customer, the address
	** $shipping_cost var contains the price calculated by the range in carrier tab
	**
	*/
	public function getOrderShippingCost($params, $shipping_cost)
	{
		// This example returns shipping cost with overcost set in the back-office, but you can call a webservice or calculate what you want before returning the final value to the Cart
		if ($this->id_carrier == (int)(Configuration::get('BRASPRESS_CARRIER_ID')))
		{
			// todo
		}

		// If the carrier is not known, you can return false, the carrier won't appear in the order process
		return false;
	}

	public function getOrderShippingCostExternal($params)
	{
		if ($this->id_carrier == (int)(Configuration::get('BRASPRESS_CARRIER_ID')))
		{
			// calcula conforme tabela braspress configurada no modulo
			/**
			 * 1 - Obtemos CEP do customer
			 * 2 - Encontramos a qual regiao o customer pertence
			 * 3 - Obtemos as taxas na tabela para a regiao do customer
			 * 4 - Calculamos taxas conforme tabela Braspress
			 * 5 - retornamos valor calculado
			 */
			$db = Db::getInstance();

			$cart = new Cart($params->id);
			$customer = new Customer($params->id_customer);
			$address = new Address($params->id_address_delivery);
			$postcode = (int)substr($address->postcode, 0, 5);

			$totalNota = $cart->getOrderTotal(true, Cart::BOTH);

			$regiao = array();
			$faixaPeso = array();

			// regiao
			$sqlRegiao = 'SELECT r.* FROM '._DB_PREFIX_.'braspress_regiao r'.
				' WHERE r.cep_inicial >= '.$postcode.' AND r.cep_final <= '.$postcode;
			$regiao = $db->getRow($sqlRegiao);
			if (!$regiao)
				return false;

			// faixa de peso
			$peso = $cart->getTotalWeight();
			$sqlFaixaPeso = 'SELECT f.* FROM '._DB_PREFIX_.'braspress_faixa_peso'.
				' WHERE f.peso_inicial >= '.$peso.' AND f.peso_final <= '.$peso;
			$faixaPeso = $db->getRow($sqlFaixaPeso);
			if (!$faixaPeso)
				return false;

			// tarifas
			$sqlTaxas = 'SELECT t.* FROM '._DB_PREFIX_.'braspress_taxas_frete'.
				' WHERE t.braspress_regiao_id = '.$regiao['id'].' AND t.braspress_faixa_peso_id = '.$faixaPeso['id'];
			$taxas = $db->getRow($sqlTaxas);
			if (!$taxas)
				return false;

			// TRF
			$sqlTrf = 'SELECT t.* FROM '._DB_PREFIX_.'braspress_trf_regiao'.
				' WHERE t.braspress_taxas_frete_id = '.$faixaPeso['id'].' AND t.braspress_regiao_id = '.$regiao['id'];
			$trf = $db->getRow($sqlTrf);
			// TAS RODO
			$sqlTrf = 'SELECT t.* FROM '._DB_PREFIX_.'braspress_tas_rodo_regiao'.
				' WHERE t.braspress_taxas_frete_id = '.$faixaPeso['id'].' AND t.braspress_regiao_id = '.$regiao['id'];
			$tasrodo = $db->getRow($sqlTrf);
			// SUFRAMA
			$sqlTrf = 'SELECT t.* FROM '._DB_PREFIX_.'braspress_suframa_regiao'.
				' WHERE t.braspress_taxas_frete_id = '.$faixaPeso['id'].' AND t.braspress_regiao_id = '.$regiao['id'];
			$suframa = $db->getRow($sqlTrf);

			$freteSubtotal = 0;
			$freteTotal = 0;

			$fv = ($totalNota * $taxas['fv'] / 100);
			if ($faixaPeso['usar_fpk'] == 1) {
				$freteSubtotal = $taxas['fpk'] * $peso + $fv;
			} else {
				$freteSubtotal = $taxas['fp'] + $fv;
			}
			$freteTotal += $freteSubtotal;

			// pedagio
			// Se for 100Kg 3,90. Acima de 100Kg será cobrado mais 3,90. e assim sucessivamente.
			$extraPedagio = (int)($peso / 100);
			$multiplicador = 1;
			if ($extraPedagio > 0)
				$multiplicador = $extraPedagio;
			$pedagio = $taxas['pedagio'] * $multiplicador;
			$freteTotal += $pedagio;

			// gris rodo
			$valGris = $taxas['gris_rodo'] * $totalNota / 100;

			// trf
			if ($trf) {
				$valTrf = $taxas['trf'] * $totalNota / 100;
				$freteTotal += $valTrf;
			}

			// tas rodo
			if ($tasrodo) {
				$freteTotal += $taxas['tas_rodo'];
			}

			// suframa
			if ($suframa) {
				$freteTotal += $taxas['suframa'];
			}

			// adm rodo
			if ($taxas['adm_rodo']) {
				$freteTotal += ($freteSubtotal * $taxas['adm_rodo'] / 100);
			}

			return (float)$freteTotal;
		}

		return false;
	}


	/**
	 * Cria banco de dados com os dados da transportadora BRASPRESS
	 * para configuracao posterior do modulo
	 */
	protected function createSchema()
	{
		$db = Db::getInstance();

		include(dirname(__FILE__).'/sql/install.php');
		foreach ($sql as $s)
			if (!$db->Execute($s))
				return false;

		return true;
	}

	/**
	 * Drops braspress carrier schema
	 */
	protected function dropSchema()
	{
		$db = Db::getInstance();

		include(dirname(__FILE__).'/sql/uninstall.php');
		foreach ($sql as $s)
			if (!$db->Execute($s))
				return false;

		return false;
	}
}