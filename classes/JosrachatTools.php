<?php
/**
 * josrachat - Herramientas con las que la IA consulta la tienda.
 * La IA nunca ve la base de datos: pide datos con estas funciones y solo recibe
 * lo que se le devuelve aquí (sin direcciones ni datos personales de otros clientes).
 *
 * @author    josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JosrachatTools
{
    /** @var Context */
    protected $context;
    protected $idLang;
    protected $idShop;
    /** Se pone a true cuando la IA pide poner al cliente en contacto con una persona. */
    public $contactRequested = false;
    public $contactReason = '';
    public $orderLookupsFailed = 0;

    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->idLang = (int) $context->language->id;
        $this->idShop = (int) $context->shop->id;
    }

    /** Definición de herramientas para la API. */
    public static function definitions()
    {
        $tools = array(
            array(
                'name' => 'buscar_productos',
                'description' => 'Busca productos del catálogo de la tienda por palabras (nombre, categoría, marca, referencia, etiquetas, descripción). Úsala siempre antes de recomendar o hablar de un producto. Si no encuentra nada, prueba con sinónimos o palabras más generales (por ejemplo "ternera" en vez de "chuletón para guisar").',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'consulta' => array('type' => 'string', 'description' => 'Palabras clave, sin frases largas.'),
                        'categoria' => array('type' => 'string', 'description' => 'Opcional: nombre de categoría para filtrar.'),
                    ),
                    'required' => array('consulta'),
                ),
            ),
            array(
                'name' => 'ver_producto',
                'description' => 'Ficha completa de un producto: descripción, características, combinaciones (tallas, pesos, colores…) con su precio y stock, marca y enlace.',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array('id_producto' => array('type' => 'integer')),
                    'required' => array('id_producto'),
                ),
            ),
            array(
                'name' => 'productos_mas_vendidos',
                'description' => 'Los productos que más se venden en la tienda. Útil para recomendar cuando el cliente no sabe qué elegir.',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array('limite' => array('type' => 'integer', 'description' => 'Entre 1 y 10. Por defecto 5.')),
                ),
            ),
            array(
                'name' => 'gastos_envio',
                'description' => 'Transportistas, plazos y precios de envío para un país, y el importe a partir del cual el envío es gratis.',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array('pais' => array('type' => 'string', 'description' => 'Nombre o código ISO del país. Si no se indica, el de la tienda.')),
                ),
            ),
            array(
                'name' => 'leer_pagina',
                'description' => 'Lee una página de información de la tienda (envíos, devoluciones, condiciones, quiénes somos…) o un documento subido por la tienda. Los identificadores están en la lista de páginas y documentos de las instrucciones.',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'tipo' => array('type' => 'string', 'enum' => array('pagina', 'documento')),
                        'id' => array('type' => 'integer'),
                    ),
                    'required' => array('tipo', 'id'),
                ),
            ),
            array(
                'name' => 'estado_pedido',
                'description' => 'Estado, transportista y seguimiento de un pedido. Si el cliente ha iniciado sesión se usan sus pedidos sin pedir nada. Si no, hacen falta la referencia o número del pedido Y el email con el que se compró: pídeselos antes de llamar.',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'referencia' => array('type' => 'string', 'description' => 'Referencia o número del pedido.'),
                        'email' => array('type' => 'string'),
                    ),
                ),
            ),
            array(
                'name' => 'contactar_persona',
                'description' => 'Muestra al cliente las opciones para hablar con una persona de la tienda (WhatsApp, teléfono, formulario). Llámala si el cliente lo pide, si está molesto o tiene una queja, o si no encuentras la información para responder bien.',
                'input_schema' => array(
                    'type' => 'object',
                    'properties' => array(
                        'motivo' => array('type' => 'string', 'enum' => array('lo_pide_el_cliente', 'sin_informacion', 'queja', 'otro')),
                        'resumen' => array('type' => 'string', 'description' => 'Una frase con lo que necesita el cliente.'),
                    ),
                    'required' => array('motivo'),
                ),
            ),
        );
        $last = count($tools) - 1;
        $tools[$last]['cache_control'] = array('type' => 'ephemeral');

        return $tools;
    }

    /** Ejecuta una herramienta y devuelve texto para la IA. */
    public function run($name, $input)
    {
        if (!is_array($input)) {
            $input = array();
        }
        try {
            switch ($name) {
                case 'buscar_productos':
                    return $this->searchProducts(isset($input['consulta']) ? (string) $input['consulta'] : '', isset($input['categoria']) ? (string) $input['categoria'] : '');
                case 'ver_producto':
                    return $this->productDetail(isset($input['id_producto']) ? (int) $input['id_producto'] : 0);
                case 'productos_mas_vendidos':
                    return $this->bestSellers(isset($input['limite']) ? (int) $input['limite'] : 5);
                case 'gastos_envio':
                    return $this->shipping(isset($input['pais']) ? (string) $input['pais'] : '');
                case 'leer_pagina':
                    return $this->readPage(isset($input['tipo']) ? (string) $input['tipo'] : '', isset($input['id']) ? (int) $input['id'] : 0);
                case 'estado_pedido':
                    return $this->orderStatus(isset($input['referencia']) ? (string) $input['referencia'] : '', isset($input['email']) ? (string) $input['email'] : '');
                case 'contactar_persona':
                    $this->contactRequested = true;
                    $this->contactReason = isset($input['motivo']) ? (string) $input['motivo'] : 'otro';

                    return 'Se han mostrado al cliente los botones de contacto (WhatsApp, teléfono y formulario). Díselo en una frase breve.';
            }
        } catch (Exception $e) {
            return 'Error interno al consultar la tienda: ' . $e->getMessage();
        }

        return 'Herramienta desconocida.';
    }

    // ---------------------------------------------------------------- productos

    protected function words($text)
    {
        $text = Tools::strtolower(strip_tags($text));
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $stop = array('para', 'con', 'sin', 'los', 'las', 'del', 'una', 'uno', 'unos', 'unas', 'que', 'por', 'the', 'and', 'for', 'with', 'tienes', 'tiene', 'hay', 'quiero', 'busco', 'necesito');
        $out = array();
        foreach ($parts as $w) {
            if (Tools::strlen($w) < 3 || in_array($w, $stop, true)) {
                continue;
            }
            // Plurales sencillos: chuletones -> chuleton, filetes -> filete
            if (Tools::strlen($w) > 5 && Tools::substr($w, -2) === 'es') {
                $w = Tools::substr($w, 0, -2);
            } elseif (Tools::strlen($w) > 4 && Tools::substr($w, -1) === 's') {
                $w = Tools::substr($w, 0, -1);
            }
            $out[$w] = $w;
        }

        return array_slice(array_values($out), 0, 6);
    }

    protected function searchProducts($query, $category)
    {
        $words = $this->words($query);
        if (!$words) {
            return 'Indica al menos una palabra de 3 letras para buscar.';
        }
        $l = (int) $this->idLang;
        $s = (int) $this->idShop;
        $score = array();
        $any = array();
        foreach ($words as $w) {
            $like = '"%' . pSQL($w) . '%"';
            $score[] = '(CASE WHEN pl.`name` LIKE ' . $like . ' THEN 6 ELSE 0 END)'
                . ' + (CASE WHEN cl.`name` LIKE ' . $like . ' THEN 3 ELSE 0 END)'
                . ' + (CASE WHEN m.`name` LIKE ' . $like . ' THEN 3 ELSE 0 END)'
                . ' + (CASE WHEN p.`reference` LIKE ' . $like . ' THEN 4 ELSE 0 END)'
                . ' + (CASE WHEN EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'product_tag` pt INNER JOIN `' . _DB_PREFIX_ . 'tag` t ON t.`id_tag` = pt.`id_tag` WHERE pt.`id_product` = p.`id_product` AND t.`id_lang` = ' . $l . ' AND t.`name` LIKE ' . $like . ') THEN 3 ELSE 0 END)'
                . ' + (CASE WHEN pl.`description_short` LIKE ' . $like . ' THEN 2 ELSE 0 END)'
                . ' + (CASE WHEN pl.`description` LIKE ' . $like . ' THEN 1 ELSE 0 END)';
            $any[] = 'pl.`name` LIKE ' . $like . ' OR cl.`name` LIKE ' . $like . ' OR m.`name` LIKE ' . $like
                . ' OR p.`reference` LIKE ' . $like . ' OR pl.`description_short` LIKE ' . $like . ' OR pl.`description` LIKE ' . $like
                . ' OR EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'product_tag` pt2 INNER JOIN `' . _DB_PREFIX_ . 'tag` t2 ON t2.`id_tag` = pt2.`id_tag` WHERE pt2.`id_product` = p.`id_product` AND t2.`id_lang` = ' . $l . ' AND t2.`name` LIKE ' . $like . ')';
        }
        $catFilter = '';
        if (trim($category) !== '') {
            $catLike = '"%' . pSQL(trim($category)) . '%"';
            $catFilter = ' AND EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'category_product` cp INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl2 ON cl2.`id_category` = cp.`id_category` AND cl2.`id_lang` = ' . $l . ' AND cl2.`id_shop` = ' . $s . ' WHERE cp.`id_product` = p.`id_product` AND cl2.`name` LIKE ' . $catLike . ')';
        }

        $sql = 'SELECT p.`id_product`, (' . implode(' + ', $score) . ') AS score
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $s . '
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $l . ' AND pl.`id_shop` = ' . $s . '
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = ps.`id_category_default` AND cl.`id_lang` = ' . $l . ' AND cl.`id_shop` = ' . $s . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.`id_manufacturer` = p.`id_manufacturer`
            WHERE ps.`active` = 1 AND ps.`visibility` IN ("both", "search")
              AND (' . implode(' OR ', $any) . ')' . $catFilter . '
            ORDER BY score DESC, p.`id_product` DESC
            LIMIT 8';
        $rows = Db::getInstance()->executeS($sql);
        if (!$rows) {
            return 'No hay productos que coincidan con "' . $query . '". Prueba con otras palabras o una categoría más general. Si sigue sin haber nada, di al cliente que no lo tenéis y ofrece alternativas del catálogo.';
        }
        $out = array();
        foreach ($rows as $r) {
            $out[] = $this->productSummary((int) $r['id_product']);
        }

        return "Productos encontrados:\n" . implode("\n", array_filter($out));
    }

    protected function productSummary($idProduct)
    {
        $p = new Product($idProduct, false, $this->idLang, $this->idShop);
        if (!Validate::isLoadedObject($p)) {
            return '';
        }
        $line = '- [id ' . (int) $p->id . '] ' . $p->name;
        if ($p->reference) {
            $line .= ' (ref. ' . $p->reference . ')';
        }
        $line .= ' | ' . $this->priceText($p);
        $line .= ' | ' . $this->stockText($p, 0);
        $cat = new Category((int) $p->id_category_default, $this->idLang, $this->idShop);
        if (Validate::isLoadedObject($cat)) {
            $line .= ' | Categoría: ' . $cat->name;
        }
        $short = $this->clean($p->description_short, 220);
        if ($short === '') {
            $short = $this->clean($p->description, 220);
        }
        if ($short !== '') {
            $line .= ' | ' . $short;
        }
        $line .= ' | Enlace: ' . $this->context->link->getProductLink($p);

        return $line;
    }

    protected function productDetail($idProduct)
    {
        $p = new Product($idProduct, true, $this->idLang, $this->idShop);
        if (!Validate::isLoadedObject($p) || !$p->active || !in_array($p->visibility, array('both', 'search', 'catalog'), true)) {
            return 'Ese producto no existe o no está a la venta.';
        }
        $txt = 'Producto [id ' . (int) $p->id . ']: ' . $p->name . "\n";
        if ($p->reference) {
            $txt .= 'Referencia: ' . $p->reference . "\n";
        }
        if ($p->manufacturer_name) {
            $txt .= 'Marca: ' . $p->manufacturer_name . "\n";
        }
        $txt .= 'Precio: ' . $this->priceText($p) . "\n";
        $txt .= 'Disponibilidad: ' . $this->stockText($p, 0) . "\n";
        $txt .= 'Enlace: ' . $this->context->link->getProductLink($p) . "\n";

        $features = Product::getFrontFeaturesStatic($this->idLang, (int) $p->id);
        if ($features) {
            $txt .= "Características:\n";
            foreach ($features as $f) {
                $txt .= '  ' . $f['name'] . ': ' . $f['value'] . "\n";
            }
        }

        $combos = $p->getAttributeCombinations($this->idLang, true);
        if ($combos) {
            $byIpa = array();
            foreach ($combos as $c) {
                $ipa = (int) $c['id_product_attribute'];
                if (!isset($byIpa[$ipa])) {
                    $byIpa[$ipa] = array();
                }
                $byIpa[$ipa][] = $c['group_name'] . ': ' . $c['attribute_name'];
            }
            $txt .= "Combinaciones (precio con IVA y stock):\n";
            $n = 0;
            foreach ($byIpa as $ipa => $labels) {
                if (++$n > 40) {
                    $txt .= "  …y más combinaciones.\n";
                    break;
                }
                $price = Product::getPriceStatic((int) $p->id, true, $ipa, 2);
                $txt .= '  ' . implode(', ', $labels) . ' → ' . $this->money($price) . ', ' . $this->stockText($p, $ipa) . "\n";
            }
        }

        $short = $this->clean($p->description_short, 1000);
        $long = $this->clean($p->description, 3500);
        if ($short !== '') {
            $txt .= "Resumen: " . $short . "\n";
        }
        if ($long !== '') {
            $txt .= "Descripción: " . $long . "\n";
        }
        if ($short === '' && $long === '') {
            $txt .= "La tienda no tiene descripción de este producto: puedes usar tu conocimiento general del producto, sin inventar datos concretos (peso exacto, origen, composición).\n";
        }

        return $txt;
    }

    protected function bestSellers($limit)
    {
        $limit = max(1, min(10, (int) $limit));
        $rows = Db::getInstance()->executeS(
            'SELECT s.`id_product` FROM `' . _DB_PREFIX_ . 'product_sale` s
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = s.`id_product` AND ps.`id_shop` = ' . (int) $this->idShop . '
             WHERE ps.`active` = 1 AND ps.`visibility` IN ("both", "search", "catalog")
             ORDER BY s.`quantity` DESC LIMIT ' . $limit
        );
        if (!$rows) {
            return 'Todavía no hay datos de ventas.';
        }
        $out = array();
        foreach ($rows as $r) {
            $out[] = $this->productSummary((int) $r['id_product']);
        }

        return "Más vendidos:\n" . implode("\n", array_filter($out));
    }

    protected function priceText(Product $p)
    {
        if (isset($p->show_price) && !$p->show_price) {
            return 'Precio a consultar';
        }
        $final = Product::getPriceStatic((int) $p->id, true, null, 2);
        $regular = Product::getPriceStatic((int) $p->id, true, null, 2, null, false, false);
        $txt = $this->money($final) . ' IVA incl.';
        if ($regular > $final + 0.009) {
            $txt .= ' (en oferta, antes ' . $this->money($regular) . ')';
        }
        if (!empty($p->unity) && (float) $p->unit_price_ratio > 0) {
            $txt .= ', ' . $this->money($final / (float) $p->unit_price_ratio) . '/' . $p->unity;
        }

        return $txt;
    }

    protected function stockText(Product $p, $ipa)
    {
        if (isset($p->available_for_order) && !$p->available_for_order) {
            return 'no disponible para pedir';
        }
        if (!Configuration::get('PS_STOCK_MANAGEMENT')) {
            return 'disponible';
        }
        $qty = (int) StockAvailable::getQuantityAvailableByProduct((int) $p->id, (int) $ipa, $this->idShop);
        if ($qty > 0) {
            return 'en stock';
        }
        $oos = StockAvailable::outOfStock((int) $p->id, $this->idShop);

        return Product::isAvailableWhenOutOfStock($oos) ? 'sin stock ahora, se puede pedir (tardará más)' : 'agotado';
    }

    // ---------------------------------------------------------------- envíos

    protected function shipping($country)
    {
        $idCountry = 0;
        $country = trim($country);
        if ($country !== '') {
            if (Tools::strlen($country) === 2) {
                $idCountry = (int) Country::getByIso(Tools::strtoupper($country), true);
            }
            if (!$idCountry) {
                $idCountry = (int) Db::getInstance()->getValue(
                    'SELECT c.`id_country` FROM `' . _DB_PREFIX_ . 'country` c
                     INNER JOIN `' . _DB_PREFIX_ . 'country_lang` cl ON cl.`id_country` = c.`id_country` AND cl.`id_lang` = ' . (int) $this->idLang . '
                     WHERE c.`active` = 1 AND cl.`name` LIKE "%' . pSQL($country) . '%"'
                );
            }
            if (!$idCountry) {
                return 'La tienda no envía a "' . $country . '" o ese país no está activo.';
            }
        } else {
            $idCountry = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        }
        $countryObj = new Country($idCountry, $this->idLang);
        $idZone = (int) Country::getIdZone($idCountry);
        $carriers = Carrier::getCarriers($this->idLang, true, false, $idZone, null, Carrier::ALL_CARRIERS);
        if (!$carriers) {
            return 'No hay transportistas para ' . $countryObj->name . '.';
        }
        $address = new Address();
        $address->id_country = $idCountry;
        $handling = (float) Configuration::get('PS_SHIPPING_HANDLING');

        $txt = 'Envíos a ' . $countryObj->name . ":\n";
        foreach ($carriers as $c) {
            $carrier = new Carrier((int) $c['id_carrier'], $this->idLang);
            $line = '- ' . $c['name'] . ($c['delay'] ? ' (' . $c['delay'] . ')' : '');
            if ($carrier->is_free) {
                $txt .= $line . ": gratis\n";
                continue;
            }
            if ($carrier->is_module && $carrier->shipping_external) {
                $txt .= $line . ": el precio lo calcula el transportista en el carrito\n";
                continue;
            }
            $method = $carrier->getShippingMethod();
            $table = $method === Carrier::SHIPPING_METHOD_WEIGHT ? 'range_weight' : 'range_price';
            $idCol = $method === Carrier::SHIPPING_METHOD_WEIGHT ? 'id_range_weight' : 'id_range_price';
            $ranges = Db::getInstance()->executeS(
                'SELECT r.`delimiter1`, r.`delimiter2`, d.`price` FROM `' . _DB_PREFIX_ . 'delivery` d
                 INNER JOIN `' . _DB_PREFIX_ . $table . '` r ON r.`' . $idCol . '` = d.`' . $idCol . '`
                 WHERE d.`id_carrier` = ' . (int) $carrier->id . ' AND d.`id_zone` = ' . $idZone . '
                   AND (d.`id_shop` IS NULL OR d.`id_shop` = ' . (int) $this->idShop . ')
                 ORDER BY r.`delimiter1` ASC LIMIT 12'
            );
            if (!$ranges) {
                $txt .= $line . ": precio según el carrito\n";
                continue;
            }
            $rate = (float) $carrier->getTaxesRate($address);
            $parts = array();
            foreach ($ranges as $r) {
                $price = (float) $r['price'] + ($carrier->shipping_handling ? $handling : 0);
                $price = $price * (1 + $rate / 100);
                $from = (float) $r['delimiter1'];
                $to = (float) $r['delimiter2'];
                $unit = $method === Carrier::SHIPPING_METHOD_WEIGHT ? ' ' . Configuration::get('PS_WEIGHT_UNIT') : ' €';
                $parts[] = 'de ' . rtrim(rtrim(number_format($from, 2, ',', ''), '0'), ',') . $unit . ' a ' . rtrim(rtrim(number_format($to, 2, ',', ''), '0'), ',') . $unit . ': ' . ($price > 0 ? $this->money($price) : 'gratis');
            }
            $txt .= $line . ' (' . ($method === Carrier::SHIPPING_METHOD_WEIGHT ? 'según peso' : 'según importe del pedido') . '): ' . implode('; ', $parts) . "\n";
        }
        $freePrice = (float) Configuration::get('PS_SHIPPING_FREE_PRICE');
        if ($freePrice > 0) {
            $txt .= 'Envío gratis en pedidos desde ' . $this->money($freePrice) . ".\n";
        }
        $freeWeight = (float) Configuration::get('PS_SHIPPING_FREE_WEIGHT');
        if ($freeWeight > 0) {
            $txt .= 'Envío gratis en pedidos desde ' . $freeWeight . ' ' . Configuration::get('PS_WEIGHT_UNIT') . ".\n";
        }
        $txt .= "El importe definitivo se ve en el carrito. Pueden existir promociones que no salen aquí.\n";

        return $txt;
    }

    // ---------------------------------------------------------------- páginas y documentos

    public static function cmsList($idLang, $idShop)
    {
        return Db::getInstance()->executeS(
            'SELECT c.`id_cms`, cl.`meta_title` FROM `' . _DB_PREFIX_ . 'cms` c
             INNER JOIN `' . _DB_PREFIX_ . 'cms_shop` cs ON cs.`id_cms` = c.`id_cms` AND cs.`id_shop` = ' . (int) $idShop . '
             INNER JOIN `' . _DB_PREFIX_ . 'cms_lang` cl ON cl.`id_cms` = c.`id_cms` AND cl.`id_lang` = ' . (int) $idLang . ' AND cl.`id_shop` = ' . (int) $idShop . '
             WHERE c.`active` = 1 ORDER BY c.`position` ASC LIMIT 60'
        );
    }

    public static function documentList($idShop)
    {
        return Db::getInstance()->executeS(
            'SELECT `id_document`, `title` FROM `' . _DB_PREFIX_ . 'josrachat_document`
             WHERE `id_shop` = ' . (int) $idShop . ' AND `active` = 1 ORDER BY `id_document` ASC LIMIT 60'
        );
    }

    protected function readPage($type, $id)
    {
        if ($type === 'documento') {
            $doc = Db::getInstance()->getRow(
                'SELECT `title`, `content` FROM `' . _DB_PREFIX_ . 'josrachat_document`
                 WHERE `id_document` = ' . (int) $id . ' AND `id_shop` = ' . (int) $this->idShop . ' AND `active` = 1'
            );
            if (!$doc) {
                return 'Ese documento no existe.';
            }

            return 'Documento "' . $doc['title'] . "\":\n" . Tools::substr($doc['content'], 0, 40000);
        }
        $row = Db::getInstance()->getRow(
            'SELECT cl.`meta_title`, cl.`content` FROM `' . _DB_PREFIX_ . 'cms` c
             INNER JOIN `' . _DB_PREFIX_ . 'cms_lang` cl ON cl.`id_cms` = c.`id_cms` AND cl.`id_lang` = ' . (int) $this->idLang . ' AND cl.`id_shop` = ' . (int) $this->idShop . '
             WHERE c.`id_cms` = ' . (int) $id . ' AND c.`active` = 1'
        );
        if (!$row) {
            return 'Esa página no existe.';
        }
        $link = $this->context->link->getCMSLink((int) $id);

        return 'Página "' . $row['meta_title'] . '" (' . $link . "):\n" . $this->clean($row['content'], 15000);
    }

    // ---------------------------------------------------------------- pedidos

    protected function orderStatus($reference, $email)
    {
        $reference = trim($reference);
        $email = trim($email);
        $idCustomer = ($this->context->customer && $this->context->customer->isLogged()) ? (int) $this->context->customer->id : 0;

        if ($this->orderLookupsFailed >= 5) {
            return 'Demasiados intentos fallidos. Ofrece el contacto con una persona.';
        }

        $where = '';
        if ($idCustomer) {
            $where = 'o.`id_customer` = ' . $idCustomer;
            if ($reference !== '') {
                $where .= ' AND (o.`reference` = "' . pSQL($reference) . '"' . (ctype_digit($reference) ? ' OR o.`id_order` = ' . (int) $reference : '') . ')';
            }
        } else {
            if ($reference === '' || !Validate::isEmail($email)) {
                return 'Pide al cliente la referencia o número del pedido y el email con el que compró (o que inicie sesión).';
            }
            $where = 'c.`email` = "' . pSQL($email) . '" AND (o.`reference` = "' . pSQL($reference) . '"' . (ctype_digit($reference) ? ' OR o.`id_order` = ' . (int) $reference : '') . ')';
        }

        $db = Db::getInstance();
        $rows = $db->executeS(
            'SELECT o.`id_order`, o.`reference`, o.`date_add`, o.`payment`, o.`total_paid_tax_incl`, o.`total_shipping_tax_incl`,
                    o.`id_currency`, o.`current_state`, osl.`name` AS state
             FROM `' . _DB_PREFIX_ . 'orders` o
             INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = o.`id_customer`
             LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . (int) $this->idLang . '
             WHERE ' . $where . ' AND o.`id_shop` = ' . (int) $this->idShop . '
             ORDER BY o.`date_add` DESC LIMIT 5'
        );
        if (!$rows) {
            ++$this->orderLookupsFailed;

            return $idCustomer ? 'Este cliente no tiene pedidos con esos datos.' : 'No hay ningún pedido con esa referencia y ese email. Pide que lo revise.';
        }

        $txt = count($rows) > 1 && $reference === ''
            ? "Últimos pedidos del cliente (si no ha dicho cuál, enséñale la lista corta y pregúntale cuál quiere ver en detalle):\n"
            : "Datos del pedido:\n";
        foreach ($rows as $r) {
            $idOrder = (int) $r['id_order'];
            $currencyObj = new Currency((int) $r['id_currency']);
            $currency = Validate::isLoadedObject($currencyObj) ? $currencyObj->iso_code : null;

            $history = $db->executeS(
                'SELECT oh.`date_add`, osl.`name`, os.`shipped`
                 FROM `' . _DB_PREFIX_ . 'order_history` oh
                 LEFT JOIN `' . _DB_PREFIX_ . 'order_state` os ON os.`id_order_state` = oh.`id_order_state`
                 LEFT JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON osl.`id_order_state` = oh.`id_order_state` AND osl.`id_lang` = ' . (int) $this->idLang . '
                 WHERE oh.`id_order` = ' . $idOrder . '
                 ORDER BY oh.`date_add` ASC, oh.`id_order_history` ASC'
            );
            $carrier = $db->getRow(
                'SELECT ca.`name`, ca.`url`, oc.`tracking_number`
                 FROM `' . _DB_PREFIX_ . 'order_carrier` oc
                 LEFT JOIN `' . _DB_PREFIX_ . 'carrier` ca ON ca.`id_carrier` = oc.`id_carrier`
                 WHERE oc.`id_order` = ' . $idOrder . '
                 ORDER BY oc.`id_order_carrier` DESC'
            );

            $lastUpdate = $r['date_add'];
            $shippedOn = '';
            $lines = array();
            foreach ($history ? $history : array() as $h) {
                $lastUpdate = $h['date_add'];
                if ((int) $h['shipped'] && $shippedOn === '') {
                    $shippedOn = $h['date_add'];
                }
                $lines[] = '  - ' . date('d/m/Y H:i', strtotime($h['date_add'])) . ': ' . ($h['name'] ? $h['name'] : 'estado sin nombre');
            }

            $txt .= "\nReferencia: " . $r['reference'] . ' (número ' . $idOrder . ")\n";
            $txt .= 'Estado actual: ' . ($r['state'] ? $r['state'] : 'sin estado') . "\n";
            $txt .= 'Fecha del pedido: ' . date('d/m/Y H:i', strtotime($r['date_add'])) . "\n";
            $txt .= 'Última actualización: ' . date('d/m/Y H:i', strtotime($lastUpdate)) . "\n";
            $txt .= 'Método de pago: ' . ($r['payment'] !== '' ? $r['payment'] : 'no indicado') . "\n";
            $txt .= 'Total pagado: ' . $this->money((float) $r['total_paid_tax_incl'], $currency) . "\n";
            $txt .= 'Gastos de envío: ' . ((float) $r['total_shipping_tax_incl'] > 0 ? $this->money((float) $r['total_shipping_tax_incl'], $currency) : 'gratis') . "\n";
            $txt .= 'Transportista: ' . ($carrier && $carrier['name'] ? $carrier['name'] : 'sin asignar') . "\n";
            if ($carrier && trim((string) $carrier['tracking_number']) !== '') {
                $tracking = trim($carrier['tracking_number']);
                $url = $carrier['url'] ? str_replace('@', rawurlencode($tracking), $carrier['url']) : '';
                $txt .= 'Número de seguimiento: ' . $tracking . ($url ? ' (enlace: ' . $url . ')' : ' (sin enlace de seguimiento)') . "\n";
            } else {
                $txt .= "Número de seguimiento: no disponible\n";
            }
            $txt .= 'Enviado: ' . ($shippedOn !== '' ? 'sí, el ' . date('d/m/Y', strtotime($shippedOn)) : 'todavía no') . "\n";
            if ($lines) {
                $txt .= "Historial de estados:\n" . implode("\n", $lines) . "\n";
            }
        }

        return $txt;
    }

    // ---------------------------------------------------------------- utilidades

    protected function clean($html, $max)
    {
        $text = html_entity_decode(strip_tags(str_replace(array('<br>', '<br/>', '<br />', '</p>', '</li>'), "\n", (string) $html)), ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace("/[ \t]+/", ' ', preg_replace("/\n\s*\n+/", "\n", $text)));
        if (Tools::strlen($text) > $max) {
            $text = Tools::substr($text, 0, $max) . '…';
        }

        return $text;
    }

    public function money($amount, $iso = null)
    {
        return Josrachat::formatMoney($amount, $iso);
    }
}
