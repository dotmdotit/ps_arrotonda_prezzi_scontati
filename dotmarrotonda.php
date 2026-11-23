<?php
/**
 * Arrotonda prezzi scontati — arrotonda il prezzo scontato IVA inclusa (es. 2.114,10 € → 2.114 €)
 * in tutto il negozio: schede, listati, carrello, ordini, fatture e feed.
 *
 * @author dotm.it
 * @license MIT (vedi LICENSE)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Dotmarrotonda extends Module
{
    const CFG_MODE = 'DOTMARROTONDA_MODE';
    const CFG_IPS = 'DOTMARROTONDA_IPS';
    const CFG_STEP = 'DOTMARROTONDA_STEP';
    const CFG_METHOD = 'DOTMARROTONDA_METHOD';
    const CFG_AMOUNT = 'DOTMARROTONDA_AMOUNT';

    /** spento / solo per gli IP di prova / attivo per tutti */
    const MODE_OFF = 0;
    const MODE_IP = 1;
    const MODE_ALL = 2;

    /** @var bool|null */
    protected static $active = null;

    public function __construct()
    {
        $this->name = 'dotmarrotonda';
        $this->tab = 'pricing_promotion';
        $this->version = '1.0.0';
        $this->author = 'dotm.it';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.7.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = 'Arrotonda prezzi scontati';
        $this->description = 'Arrotonda il prezzo scontato IVA inclusa all\'euro (o al passo scelto) quando il prodotto ha uno sconto in percentuale: 2.114,10 € → 2.114 €.';
    }

    /* ------------------------------------------------------------------
     * Installazione
     * ------------------------------------------------------------------ */

    /** PrestaShop 8.1+ ha l'hook actionProductPriceCalculation; prima serve l'override di Product */
    public static function hasPriceHook()
    {
        return version_compare(_PS_VERSION_, '8.1.0', '>=');
    }

    public function install()
    {
        return Configuration::updateValue(self::CFG_MODE, self::MODE_OFF)
            && Configuration::updateValue(self::CFG_IPS, '')
            && Configuration::updateValue(self::CFG_STEP, '1')
            && Configuration::updateValue(self::CFG_METHOD, 'nearest')
            && Configuration::updateValue(self::CFG_AMOUNT, 0)
            && parent::install()
            && $this->registerHook('actionProductPriceCalculation');
    }

    public function uninstall()
    {
        foreach ([self::CFG_MODE, self::CFG_IPS, self::CFG_STEP, self::CFG_METHOD, self::CFG_AMOUNT] as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /** L'override di Product serve solo prima della 8.1 */
    public function installOverrides()
    {
        return self::hasPriceHook() ? true : parent::installOverrides();
    }

    public function uninstallOverrides()
    {
        return self::hasPriceHook() ? true : parent::uninstallOverrides();
    }

    /* ------------------------------------------------------------------
     * Arrotondamento
     * ------------------------------------------------------------------ */

    /** Arrotondamento attivo per questa richiesta (spento / solo IP di prova / tutti) */
    public static function isActive()
    {
        if (self::$active !== null) {
            return self::$active;
        }
        $mode = (int) Configuration::get(self::CFG_MODE);
        if ($mode === self::MODE_ALL) {
            return self::$active = true;
        }
        if ($mode === self::MODE_IP) {
            $ips = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) Configuration::get(self::CFG_IPS))));

            return self::$active = in_array(Tools::getRemoteAddr(), $ips, true);
        }

        return self::$active = false;
    }

    /** Il prezzo specifico applicato è uno sconto da arrotondare? */
    public static function appliesTo($specificPrice)
    {
        if (!is_array($specificPrice) || (float) $specificPrice['reduction'] <= 0) {
            return false;
        }
        if ($specificPrice['reduction_type'] === 'percentage') {
            return true;
        }

        return $specificPrice['reduction_type'] === 'amount' && (bool) Configuration::get(self::CFG_AMOUNT);
    }

    /** Arrotonda un prezzo al passo configurato (1 €, 0,50 €, …) nel verso configurato */
    public static function roundPrice($price)
    {
        $step = (float) Configuration::get(self::CFG_STEP);
        if ($step <= 0) {
            $step = 1.0;
        }
        $q = $price / $step;
        switch ((string) Configuration::get(self::CFG_METHOD)) {
            case 'up':
                $q = ceil($q - 1e-9);
                break;
            case 'down':
                $q = floor($q + 1e-9);
                break;
            default:
                $q = round($q, 0, PHP_ROUND_HALF_UP);
        }

        return round($q * $step, 6);
    }

    protected static function taxCalculator($address, $idProduct, $context = null)
    {
        return TaxManagerFactory::getManager(
            $address,
            Product::getIdTaxRulesGroupByIdProduct((int) $idProduct, $context ?: Context::getContext())
        )->getTaxCalculator();
    }

    /**
     * PrestaShop 8.1+: hook alla fine di Product::priceCalculation. Il prezzo e lo sconto arrivano
     * per riferimento, nella modalità (con o senza IVA) della chiamata.
     */
    public function hookActionProductPriceCalculation($params)
    {
        if (!self::isActive() || (!$params['use_reduc'] && !$params['only_reduc']) || !self::appliesTo($params['specific_price'])) {
            return;
        }
        $useTax = (bool) $params['use_tax'];
        $price = (float) $params['price'];
        $reduction = (float) $params['specific_price_reduction'];
        $discounted = $params['use_reduc'] ? $price : $price - $reduction;

        $calculator = $useTax ? null : self::taxCalculator($params['address'], $params['id_product'], $params['context']);
        $discountedTi = $useTax ? $discounted : $calculator->addTaxes($discounted);
        $deltaTi = $discountedTi - self::roundPrice($discountedTi);
        if (abs($deltaTi) < 0.000001) {
            return;
        }
        $delta = $useTax ? $deltaTi : $calculator->removeTaxes($deltaTi);

        // la differenza va nello sconto: prezzo pieno - sconto = prezzo arrotondato
        $params['specific_price_reduction'] = $reduction + $delta;
        if ($params['use_reduc']) {
            $params['price'] = $price - $delta;
        }
    }

    /**
     * PrestaShop 1.7.7 – 8.0: chiamato dall'override di Product::priceCalculation con il prezzo già calcolato.
     */
    public static function legacyPriceCalculation($price, array $a)
    {
        if (self::hasPriceHook() || !self::isActive() || (!$a['use_reduc'] && !$a['only_reduc']) || !self::appliesTo($a['specific_price'])) {
            return $price;
        }
        // prezzo scontato IVA inclusa, con gli stessi parametri
        $sp = null;
        $discountedTi = ProductCore::priceCalculation(
            $a['id_shop'], $a['id_product'], $a['id_product_attribute'], $a['id_country'], $a['id_state'], $a['zipcode'],
            $a['id_currency'], $a['id_group'], $a['quantity'], true, 6, false, true, $a['with_ecotax'], $sp,
            $a['use_group_reduction'], $a['id_customer'], $a['use_customer_price'], $a['id_cart'], $a['real_quantity'],
            $a['id_customization']
        );
        if ($discountedTi === null) {
            return $price;
        }
        $deltaTi = (float) $discountedTi - self::roundPrice((float) $discountedTi);
        if (abs($deltaTi) < 0.000001) {
            return $price;
        }
        $delta = $deltaTi;
        if (!$a['use_tax']) {
            $address = new Address();
            $address->id_country = (int) $a['id_country'];
            $address->id_state = (int) $a['id_state'];
            $address->postcode = $a['zipcode'];
            $delta = self::taxCalculator($address, $a['id_product'])->removeTaxes($deltaTi);
        }
        if ($a['only_reduc']) {
            return Tools::ps_round($price + $delta, (int) $a['decimals']);
        }

        return max(0, Tools::ps_round($price - $delta, (int) $a['decimals']));
    }

    /* ------------------------------------------------------------------
     * Configurazione
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitDotmarrotonda')) {
            $step = str_replace(',', '.', (string) Tools::getValue(self::CFG_STEP));
            $method = (string) Tools::getValue(self::CFG_METHOD);
            if (!in_array($step, ['0.1', '0.5', '1', '5', '10'], true)) {
                $output .= $this->displayError('Passo di arrotondamento non valido.');
            } else {
                Configuration::updateValue(self::CFG_MODE, (int) Tools::getValue(self::CFG_MODE));
                Configuration::updateValue(self::CFG_IPS, trim((string) Tools::getValue(self::CFG_IPS)));
                Configuration::updateValue(self::CFG_STEP, $step);
                Configuration::updateValue(self::CFG_METHOD, in_array($method, ['nearest', 'up', 'down'], true) ? $method : 'nearest');
                Configuration::updateValue(self::CFG_AMOUNT, (int) (bool) Tools::getValue(self::CFG_AMOUNT));
                $output .= $this->displayConfirmation('Impostazioni salvate.');
            }
        }

        $output .= '<div class="alert alert-info"><p>'
            . (self::hasPriceHook()
                ? 'PrestaShop ' . _PS_VERSION_ . ': il modulo usa l\'hook <code>actionProductPriceCalculation</code>, senza override.'
                : 'PrestaShop ' . _PS_VERSION_ . ': il modulo usa l\'override di <code>Product::priceCalculation</code> installato con il modulo.')
            . '</p><p>Il prezzo arrotondato vale ovunque: schede, listati, carrello, ordini, fatture e feed. '
            . 'La differenza viene aggiunta o tolta allo sconto, quindi prezzo pieno − sconto = prezzo arrotondato. '
            . 'Per mostrare prezzo pieno barrato e risparmio anche nel checkout e nelle email c\'è il modulo <strong>dotmsconti</strong>.</p></div>';

        return $output . $this->renderForm();
    }

    protected function renderForm()
    {
        $form = [
            'form' => [
                'legend' => ['title' => 'Arrotondamento prezzi scontati', 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'select',
                        'label' => 'Arrotonda i prezzi scontati',
                        'name' => self::CFG_MODE,
                        'desc' => 'Prova prima con «Solo per gli IP di prova»: i clienti continuano a vedere i prezzi di prima.',
                        'options' => [
                            'query' => [
                                ['id' => self::MODE_OFF, 'name' => 'No'],
                                ['id' => self::MODE_IP, 'name' => 'Solo per gli IP di prova'],
                                ['id' => self::MODE_ALL, 'name' => 'Sì, per tutti'],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => 'IP di prova',
                        'name' => self::CFG_IPS,
                        'desc' => 'Separati da virgola. Il tuo IP attuale: ' . Tools::getRemoteAddr(),
                    ],
                    [
                        'type' => 'select',
                        'label' => 'Arrotonda a',
                        'name' => self::CFG_STEP,
                        'options' => [
                            'query' => [
                                ['id' => '0.1', 'name' => '0,10'],
                                ['id' => '0.5', 'name' => '0,50'],
                                ['id' => '1', 'name' => '1 (euro intero)'],
                                ['id' => '5', 'name' => '5'],
                                ['id' => '10', 'name' => '10'],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => 'Verso',
                        'name' => self::CFG_METHOD,
                        'options' => [
                            'query' => [
                                ['id' => 'nearest', 'name' => 'Al più vicino (2.114,10 → 2.114; 1.530,90 → 1.531)'],
                                ['id' => 'down', 'name' => 'Per difetto (sempre a favore del cliente)'],
                                ['id' => 'up', 'name' => 'Per eccesso'],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => 'Anche gli sconti in importo',
                        'name' => self::CFG_AMOUNT,
                        'is_bool' => true,
                        'desc' => 'Di norma si arrotondano solo gli sconti in percentuale.',
                        'values' => [
                            ['id' => 'amount_on', 'value' => 1, 'label' => 'Sì'],
                            ['id' => 'amount_off', 'value' => 0, 'label' => 'No'],
                        ],
                    ],
                ],
                'submit' => ['title' => 'Salva'],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitDotmarrotonda';
        $helper->default_form_language = (int) $this->context->language->id;
        $step = (float) Configuration::get(self::CFG_STEP);
        $helper->fields_value = [
            self::CFG_MODE => (int) Configuration::get(self::CFG_MODE),
            self::CFG_IPS => (string) Configuration::get(self::CFG_IPS),
            self::CFG_STEP => rtrim(rtrim(number_format($step > 0 ? $step : 1, 2, '.', ''), '0'), '.'),
            self::CFG_METHOD => (string) Configuration::get(self::CFG_METHOD) ?: 'nearest',
            self::CFG_AMOUNT => (int) Configuration::get(self::CFG_AMOUNT),
        ];

        return $helper->generateForm([$form]);
    }
}
