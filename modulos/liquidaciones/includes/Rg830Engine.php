<?php
/**
 * Motor de Cálculo RG 830 - Retenciones Impuesto a las Ganancias
 * Soporta: Porcentaje directo y Escala por tramos
 * Precedencia: Override → Obra → Proveedor → Default (vigencia RG830)
 */
class Rg830Engine
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Calcula todas las retenciones para una liquidación
     * @param array $params [
     *   'empresa_id', 'obra_id', 'fecha_pago',
     *   'importe_total', 'importe_pago', 'importe_iva', 'importe_neto',
     *   'alicuota_iva_contenido',
     *   'obra_tipo' (INGENIERIA|ARQUITECTURA|OTRA),
     *   'obra_exencion_ganancias', 'obra_exencion_iva', 'obra_exencion_iibb',
     *   'fondo_reparo_monto',
     *   'ret_otras_monto', 'multas_monto',
     *   'ganancias_concepto_id',
     *   'iibb_categoria_id', 'iibb_alicuota', 'iibb_tipo_agente'
     * ]
     * @return array [
     *   'importe_liquidado', 'base_imponible',
     *   'fondo_reparo', 'ret_suss', 'ret_ganancias', 'ret_iibb', 'ret_otras', 'multas',
     *   'items' => [...],
     *   'total_retenciones', 'neto_a_pagar'
     * ]
     */
    public function calcular(array $params): array
    {
        $empresa = $this->getEmpresa($params['empresa_id']);
        if (!$empresa) {
            throw new \RuntimeException("Empresa no encontrada (ID: {$params['empresa_id']})");
        }

        // Importe liquidado = pago parcial (si se indica) o total factura
        $importeTotal = (float)$params['importe_total'];
        $importePago = !empty($params['importe_pago']) ? (float)$params['importe_pago'] : $importeTotal;
        $importeLiquidado = $importePago;

        // 1. Base imponible = Importe pago - IVA proporcional
        $baseImponible = $this->calcularBaseImponible($params);

        // 2. Calcular cada retención sobre la base (con su propio mínimo)
        $items = [];
        $totalRetenciones = 0;

        // Helper: detectar si hay override manual (valor numérico > 0 o explícitamente 0)
        $hasOverride = function($key) use ($params) {
            return isset($params[$key]) && $params[$key] !== '' && $params[$key] !== null;
        };

        // --- FONDO DE REPARO (default 5% del pago, editable) ---
        $fondoReparoPct = (float)($params['fondo_reparo_pct'] ?? 5.00);
        $fondoReparoAuto = round($importeLiquidado * $fondoReparoPct / 100, 2);
        $fondoReparo = $hasOverride('fondo_reparo_monto')
            ? round((float)$params['fondo_reparo_monto'], 2)
            : $fondoReparoAuto;
        $totalRetenciones += $fondoReparo;

        // --- SUSS (calculado, con override manual posible) ---
        $retSuss = 0;
        $retSussAuto = 0;
        $itemSuss = $this->calcularRetencionSUSS($empresa, $params, $baseImponible);
        if ($itemSuss) {
            $retSussAuto = $itemSuss['importe_retencion'];
            $items[] = $itemSuss;
        }
        if ($hasOverride('override_suss')) {
            $retSuss = round((float)$params['override_suss'], 2);
        } else {
            $retSuss = $retSussAuto;
        }
        $totalRetenciones += $retSuss;

        // --- GANANCIAS (calculado, con override manual posible) ---
        $retGanancias = 0;
        $retGananciasAuto = 0;
        if (empty($params['obra_exencion_ganancias'])) {
            $itemGan = $this->calcularRetencionGanancias($empresa, $params, $baseImponible);
            if ($itemGan) {
                $retGananciasAuto = $itemGan['importe_retencion'];
                $items[] = $itemGan;
            }
        }
        if ($hasOverride('override_ganancias')) {
            $retGanancias = round((float)$params['override_ganancias'], 2);
        } else {
            $retGanancias = $retGananciasAuto;
        }
        $totalRetenciones += $retGanancias;

        // --- IIBB (calculado, con override manual posible) ---
        $retIibb = 0;
        $retIibbAuto = 0;
        if (empty($params['obra_exencion_iibb'])) {
            $itemIibb = $this->calcularRetencionIIBB($empresa, $params, $baseImponible);
            if ($itemIibb) {
                $retIibbAuto = $itemIibb['importe_retencion'];
                $items[] = $itemIibb;
            }
        }
        if ($hasOverride('override_iibb')) {
            $retIibb = round((float)$params['override_iibb'], 2);
        } else {
            $retIibb = $retIibbAuto;
        }
        $totalRetenciones += $retIibb;

        // --- IVA (calculado, con override manual posible) ---
        $retIva = 0;
        $retIvaAuto = 0;
        if (empty($params['obra_exencion_iva'])) {
            $itemIva = $this->calcularRetencionIVA($empresa, $params, $baseImponible);
            if ($itemIva) {
                $retIvaAuto = $itemIva['importe_retencion'];
                $items[] = $itemIva;
            }
        }
        if ($hasOverride('override_iva')) {
            $retIva = round((float)$params['override_iva'], 2);
        } else {
            $retIva = $retIvaAuto;
        }
        $totalRetenciones += $retIva;

        // --- RET. OTRAS (manual) ---
        $retOtras = round((float)($params['ret_otras_monto'] ?? 0), 2);
        $totalRetenciones += $retOtras;

        // --- MULTAS (manual) ---
        $multas = round((float)($params['multas_monto'] ?? 0), 2);
        $totalRetenciones += $multas;

        $netoAPagar = $importeLiquidado - $totalRetenciones;

        return [
            'importe_liquidado' => round($importeLiquidado, 2),
            'base_imponible'    => round($baseImponible, 2),
            'fondo_reparo_pct'  => $fondoReparoPct,
            'fondo_reparo_auto' => $fondoReparoAuto,
            'fondo_reparo'      => $fondoReparo,
            'ret_suss'          => $retSuss,
            'ret_suss_auto'     => $retSussAuto,
            'ret_ganancias'     => $retGanancias,
            'ret_ganancias_auto'=> $retGananciasAuto,
            'ret_iibb'          => $retIibb,
            'ret_iibb_auto'     => $retIibbAuto,
            'ret_iva'           => $retIva,
            'ret_iva_auto'      => $retIvaAuto,
            'ret_otras'         => $retOtras,
            'multas'            => $multas,
            'items'             => $items,
            'total_retenciones' => round($totalRetenciones, 2),
            'neto_a_pagar'      => round($netoAPagar, 2),
        ];
    }

    /**
     * Base imponible = Total - IVA (se detrae siempre el IVA)
     * El IVA se calcula según alícuota contenido si no viene discriminado
     */
    public function calcularBaseImponible(array $params): float
    {
        $importeTotal = (float)$params['importe_total'];
        $importePago = !empty($params['importe_pago']) ? (float)$params['importe_pago'] : $importeTotal;
        $alicIva = (float)($params['alicuota_iva_contenido'] ?? 21.00);

        // Si es pago parcial, el IVA se prorratea proporcionalmente
        if ($importePago < $importeTotal && $importeTotal > 0) {
            $proporcion = $importePago / $importeTotal;
            $ivaTotal = (float)($params['importe_iva'] ?? 0);
            if ($ivaTotal <= 0 && $alicIva > 0) {
                $ivaTotal = round($importeTotal - ($importeTotal / (1 + $alicIva / 100)), 2);
            }
            $iva = round($ivaTotal * $proporcion, 2);
        } else {
            $iva = (float)($params['importe_iva'] ?? 0);
            if ($iva <= 0 && $alicIva > 0) {
                $iva = round($importePago - ($importePago / (1 + $alicIva / 100)), 2);
            }
        }

        // Siempre detraer IVA de la base para retenciones
        $base = $importePago - $iva;

        return max(round($base, 2), 0);
    }

    /**
     * Retención Ganancias (RG 830)
     */
    private function calcularRetencionGanancias(array $empresa, array $params, float $baseImponible): ?array
    {
        // Verificar exclusiones del proveedor
        if ($this->tieneExclusion($empresa, 'ganancias', $params['fecha_pago'])) {
            return null;
        }

        // Obtener configuración con precedencia: Override → Obra → Proveedor → Default
        $config = $this->getConfigImpuesto('GANANCIAS', $params['empresa_id'], $params['obra_id']);
        if ($config && !$config['aplica_retencion']) {
            return null;
        }

        // Buscar concepto RG830: por ID seleccionado o default (inciso j)
        $concepto = null;
        if (!empty($params['ganancias_concepto_id'])) {
            $stmtC = $this->pdo->prepare("SELECT * FROM rg830_conceptos WHERE id = ? AND codigo = 'GANANCIAS' AND activo = 1");
            $stmtC->execute([(int)$params['ganancias_concepto_id']]);
            $concepto = $stmtC->fetch() ?: null;
        }
        if (!$concepto) {
            $concepto = $this->getConceptoGanancias();
        }
        if (!$concepto) return null;

        $vigencia = $this->getVigencia($concepto['id'], $params['fecha_pago']);
        if (!$vigencia) return null;

        // Determinar condición fiscal
        $condicion = $empresa['ganancias_condicion'] ?? 'INSCRIPTO';

        // Calcular
        $minimoNoSujeto = (float)$vigencia['minimo_no_sujeto'];
        
        // Override de mínimo
        if ($config && $config['minimo_override'] !== null) {
            $minimoNoSujeto = (float)$config['minimo_override'];
        }

        $baseSujeta = max($baseImponible - $minimoNoSujeto, 0);
        if ($baseSujeta <= 0) return null;

        // Determinar alícuota (prioridad: config override > vigencia/escala)
        if ($config && $config['porcentaje_override'] !== null) {
            $alicuota = (float)$config['porcentaje_override'];
        } elseif ($vigencia['modo_calculo'] === 'ESCALA_TRAMOS') {
            return $this->calcularPorEscala($vigencia, $baseSujeta, $concepto, $condicion, $baseImponible, $minimoNoSujeto);
        } else {
            $alicuota = ($condicion === 'INSCRIPTO')
                ? (float)$vigencia['porc_inscripto']
                : (float)$vigencia['porc_no_inscripto'];
        }

        $importeRetencion = round($baseSujeta * $alicuota / 100, 2);

        return [
            'impuesto'            => 'GANANCIAS',
            'rg830_concepto_id'   => $concepto['id'],
            'rg830_vigencia_id'   => $vigencia['id'],
            'condicion_fiscal'    => $condicion,
            'base_calculo'        => round($baseImponible, 2),
            'minimo_no_sujeto'    => round($minimoNoSujeto, 2),
            'base_sujeta'         => round($baseSujeta, 2),
            'alicuota_aplicada'   => $alicuota,
            'importe_retencion'   => $importeRetencion,
            'sicore_cod_impuesto' => '217',
            'sicore_cod_regimen'  => '830',
            'sicore_cod_comprobante' => $importeRetencion >= 0 ? '07' : '08',
        ];
    }

    /**
     * Retención IVA
     */
    private function calcularRetencionIVA(array $empresa, array $params, float $baseImponible): ?array
    {
        if ($this->tieneExclusion($empresa, 'iva', $params['fecha_pago'])) {
            return null;
        }
        if (($empresa['condicion_iva'] ?? '') === 'EXENTO' || ($empresa['condicion_iva'] ?? '') === 'MONOTRIBUTO') {
            return null;
        }

        $config = $this->getConfigImpuesto('IVA', $params['empresa_id'], $params['obra_id']);
        if ($config && !$config['aplica_retencion']) {
            return null;
        }

        $concepto = $this->getConceptoByCode('IVA', 'PAGO');
        if (!$concepto) return null;

        $vigencia = $this->getVigencia($concepto['id'], $params['fecha_pago']);
        if (!$vigencia) return null;

        $condicion = $empresa['ganancias_condicion'] ?? 'INSCRIPTO';

        if ($config && $config['porcentaje_override'] !== null) {
            $alicuota = (float)$config['porcentaje_override'];
        } else {
            $alicuota = ($condicion === 'INSCRIPTO')
                ? (float)$vigencia['porc_inscripto']
                : (float)$vigencia['porc_no_inscripto'];
        }

        // Base IVA: se retiene sobre el IVA facturado
        // Si se indica alícuota IVA contenido, recalcular IVA desde el neto
        $baseIva = (float)($params['importe_iva'] ?? 0);
        $alicuotaIvaContenido = (float)($params['alicuota_iva_contenido'] ?? 21.00);
        if ($alicuotaIvaContenido > 0 && $baseIva <= 0) {
            // Calcular IVA a partir de neto * alícuota contenido
            $neto = (float)($params['importe_neto'] ?? 0);
            if ($neto > 0) {
                $baseIva = round($neto * $alicuotaIvaContenido / 100, 2);
            }
        }
        if ($baseIva <= 0) return null;

        $importeRetencion = round($baseIva * $alicuota / 100, 2);

        return [
            'impuesto'            => 'IVA',
            'rg830_concepto_id'   => $concepto['id'],
            'rg830_vigencia_id'   => $vigencia['id'],
            'condicion_fiscal'    => $condicion,
            'base_calculo'        => round($baseIva, 2),
            'minimo_no_sujeto'    => 0,
            'base_sujeta'         => round($baseIva, 2),
            'alicuota_aplicada'   => $alicuota,
            'importe_retencion'   => $importeRetencion,
            'sicore_cod_impuesto' => '767',
            'sicore_cod_regimen'  => '830',
            'sicore_cod_comprobante' => $importeRetencion >= 0 ? '07' : '08',
        ];
    }

    /**
     * Retención SUSS
     */
    private function calcularRetencionSUSS(array $empresa, array $params, float $baseImponible): ?array
    {
        if ($this->tieneExclusion($empresa, 'suss', $params['fecha_pago'])) {
            return null;
        }

        $config = $this->getConfigImpuesto('SUSS', $params['empresa_id'], $params['obra_id']);
        if ($config && !$config['aplica_retencion']) {
            return null;
        }

        $concepto = $this->getConceptoByCode('SUSS', 'PAGO');
        if (!$concepto) return null;

        $vigencia = $this->getVigencia($concepto['id'], $params['fecha_pago']);
        if (!$vigencia) return null;

        $minimoNoSujeto = (float)$vigencia['minimo_no_sujeto'];
        if ($config && $config['minimo_override'] !== null) {
            $minimoNoSujeto = (float)$config['minimo_override'];
        }

        $baseSujeta = max($baseImponible - $minimoNoSujeto, 0);
        if ($baseSujeta <= 0) return null;

        // Alícuota según tipo de obra (prioridad: config override > obra_tipo > vigencia)
        if ($config && $config['porcentaje_override'] !== null) {
            $alicuota = (float)$config['porcentaje_override'];
        } elseif (!empty($params['obra_tipo'])) {
            $obraTipo = strtoupper($params['obra_tipo']);
            if ($obraTipo === 'INGENIERIA') {
                $alicuota = 1.20;
            } elseif ($obraTipo === 'ARQUITECTURA') {
                $alicuota = 2.50;
            } else {
                $alicuota = (float)$vigencia['porc_inscripto'];
            }
        } else {
            $alicuota = (float)$vigencia['porc_inscripto'];
        }

        $importeRetencion = round($baseSujeta * $alicuota / 100, 2);
        if ($importeRetencion <= 0) return null;

        $obraDesc = '';
        if (!empty($params['obra_tipo']) && $params['obra_tipo'] !== 'OTRA') {
            $obraDesc = ' (' . ucfirst(strtolower($params['obra_tipo'])) . ')';
        }

        return [
            'impuesto'            => 'SUSS',
            'rg830_concepto_id'   => $concepto['id'],
            'rg830_vigencia_id'   => $vigencia['id'],
            'condicion_fiscal'    => ($empresa['suss_condicion'] ?? 'EMPLEADOR') . $obraDesc,
            'base_calculo'        => round($baseImponible, 2),
            'minimo_no_sujeto'    => round($minimoNoSujeto, 2),
            'base_sujeta'         => round($baseSujeta, 2),
            'alicuota_aplicada'   => $alicuota,
            'importe_retencion'   => $importeRetencion,
            'sicore_cod_impuesto' => '351',
            'sicore_cod_regimen'  => '830',
            'sicore_cod_comprobante' => $importeRetencion >= 0 ? '07' : '08',
        ];
    }

    /**
     * Cálculo por escala de tramos (RG 830)
     */
    private function calcularPorEscala(array $vigencia, float $baseSujeta, array $concepto, string $condicion, float $baseImponible, float $minimoNoSujeto): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM rg830_escalas_tramos 
            WHERE vigencia_id = ? 
            ORDER BY orden ASC
        ");
        $stmt->execute([$vigencia['id']]);
        $tramos = $stmt->fetchAll();

        if (empty($tramos)) return null;

        $importeRetencion = 0;
        $alicuotaAplicada = 0;

        foreach ($tramos as $tramo) {
            $desde = (float)$tramo['desde'];
            $hasta = $tramo['hasta'] !== null ? (float)$tramo['hasta'] : PHP_FLOAT_MAX;

            if ($baseSujeta >= $desde && $baseSujeta <= $hasta) {
                $excedente = $baseSujeta - (float)$tramo['excedente_desde'];
                $importeRetencion = (float)$tramo['importe_fijo'] + ($excedente * (float)$tramo['porcentaje_sobre_excedente'] / 100);
                $alicuotaAplicada = (float)$tramo['porcentaje_sobre_excedente'];
                break;
            }
        }

        $importeRetencion = round($importeRetencion, 2);
        if ($importeRetencion <= 0) return null;

        return [
            'impuesto'            => 'GANANCIAS',
            'rg830_concepto_id'   => $concepto['id'],
            'rg830_vigencia_id'   => $vigencia['id'],
            'condicion_fiscal'    => $condicion,
            'base_calculo'        => round($baseImponible, 2),
            'minimo_no_sujeto'    => round($minimoNoSujeto, 2),
            'base_sujeta'         => round($baseSujeta, 2),
            'alicuota_aplicada'   => $alicuotaAplicada,
            'importe_retencion'   => $importeRetencion,
            'sicore_cod_impuesto' => '217',
            'sicore_cod_regimen'  => '830',
            'sicore_cod_comprobante' => $importeRetencion >= 0 ? '07' : '08',
        ];
    }

    /**
     * Retención Ingresos Brutos (IIBB)
     * Usa tabla iibb_categorias (Res. 276/DPR/17 Neuquén) + iibb_minimos
     */
    private function calcularRetencionIIBB(array $empresa, array $params, float $baseImponible): ?array
    {
        // Determinar alícuota: desde categoría seleccionada o manual
        $iibbCategoriaId = !empty($params['iibb_categoria_id']) ? (int)$params['iibb_categoria_id'] : null;
        $iibbAlicuota = null;
        $iibbDescripcion = '';

        if ($iibbCategoriaId) {
            $stmtCat = $this->pdo->prepare("SELECT * FROM iibb_categorias WHERE id = ? AND activo = 1");
            $stmtCat->execute([$iibbCategoriaId]);
            $cat = $stmtCat->fetch();
            if ($cat) {
                $iibbAlicuota = (float)$cat['alicuota'];
                $iibbDescripcion = $cat['codigo'] . ') ' . $cat['descripcion'];
            }
        }

        // Fallback: alícuota manual si no hay categoría
        if ($iibbAlicuota === null && isset($params['iibb_alicuota'])) {
            $iibbAlicuota = (float)$params['iibb_alicuota'];
        }

        if ($iibbAlicuota === null || $iibbAlicuota <= 0) {
            return null;
        }

        $config = $this->getConfigImpuesto('IIBB', $params['empresa_id'], $params['obra_id']);
        if ($config && !$config['aplica_retencion']) {
            return null;
        }

        // Override de alícuota desde config proveedor/obra
        if ($config && $config['porcentaje_override'] !== null) {
            $iibbAlicuota = (float)$config['porcentaje_override'];
        }

        // Obtener mínimo no sujeto según tipo de agente (default: ESTADO)
        $tipoAgente = $params['iibb_tipo_agente'] ?? 'ESTADO';
        $minimoNoSujeto = $this->getIibbMinimo($tipoAgente);

        // Override mínimo desde config
        if ($config && $config['minimo_override'] !== null) {
            $minimoNoSujeto = (float)$config['minimo_override'];
        }

        // Aplicar mínimo no sujeto
        $baseSujeta = max($baseImponible - $minimoNoSujeto, 0);
        if ($baseSujeta <= 0) return null;

        $concepto = $this->getConceptoByCode('IIBB', 'PAGO');
        $vigencia = $concepto ? $this->getVigencia($concepto['id'], $params['fecha_pago']) : null;

        $importeRetencion = round($baseSujeta * $iibbAlicuota / 100, 2);
        if ($importeRetencion <= 0) return null;

        return [
            'impuesto'            => 'IIBB',
            'rg830_concepto_id'   => $concepto['id'] ?? null,
            'rg830_vigencia_id'   => $vigencia['id'] ?? null,
            'condicion_fiscal'    => $iibbDescripcion ?: ($empresa['iibb_condicion'] ?? 'INSCRIPTO'),
            'base_calculo'        => round($baseImponible, 2),
            'minimo_no_sujeto'    => round($minimoNoSujeto, 2),
            'base_sujeta'         => round($baseSujeta, 2),
            'alicuota_aplicada'   => $iibbAlicuota,
            'importe_retencion'   => $importeRetencion,
            'sicore_cod_impuesto' => '914',
            'sicore_cod_regimen'  => '830',
            'sicore_cod_comprobante' => $importeRetencion >= 0 ? '07' : '08',
        ];
    }

    /**
     * Obtiene el mínimo no sujeto a retención IIBB por tipo de agente
     */
    private function getIibbMinimo(string $tipoAgente): float
    {
        $stmt = $this->pdo->prepare("SELECT minimo_no_sujeto FROM iibb_minimos WHERE tipo_agente = ? AND activo = 1");
        $stmt->execute([$tipoAgente]);
        $row = $stmt->fetch();
        return $row ? (float)$row['minimo_no_sujeto'] : 0;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function getEmpresa(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM empresas WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private function tieneExclusion(array $empresa, string $impuesto, string $fechaPago): bool
    {
        $campo = "exclusion_{$impuesto}";
        $desde = "exclusion_{$impuesto}_desde";
        $hasta = "exclusion_{$impuesto}_hasta";

        if (empty($empresa[$campo])) return false;

        $fecha = $fechaPago;
        if (!empty($empresa[$desde]) && $fecha < $empresa[$desde]) return false;
        if (!empty($empresa[$hasta]) && $fecha > $empresa[$hasta]) return false;

        return true;
    }

    /**
     * Obtener configuración de impuesto con precedencia:
     * Obra → Proveedor (el Override se maneja en la preliquidación)
     */
    private function getConfigImpuesto(string $impuesto, int $empresaId, int $obraId): ?array
    {
        // Primero buscar config por obra
        $stmt = $this->pdo->prepare("SELECT * FROM config_impositiva_obra WHERE obra_id = ? AND impuesto = ?");
        $stmt->execute([$obraId, $impuesto]);
        $configObra = $stmt->fetch();
        if ($configObra) return $configObra;

        // Luego por proveedor
        $stmt = $this->pdo->prepare("SELECT * FROM config_impositiva_proveedor WHERE empresa_id = ? AND impuesto = ?");
        $stmt->execute([$empresaId, $impuesto]);
        $configProv = $stmt->fetch();
        if ($configProv) return $configProv;

        return null;
    }

    private function getConceptoGanancias(): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM rg830_conceptos WHERE codigo = 'GANANCIAS' AND inciso = 'j' AND activo = 1 LIMIT 1");
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    private function getConceptoByCode(string $codigo, string $inciso): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM rg830_conceptos WHERE codigo = ? AND inciso = ? AND activo = 1 LIMIT 1");
        $stmt->execute([$codigo, $inciso]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Busca la vigencia aplicable según fecha de pago
     */
    private function getVigencia(int $conceptoId, string $fecha): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM rg830_vigencias 
            WHERE concepto_id = ? 
              AND activo = 1
              AND vigencia_desde <= ?
              AND (vigencia_hasta IS NULL OR vigencia_hasta >= ?)
            ORDER BY vigencia_desde DESC
            LIMIT 1
        ");
        $stmt->execute([$conceptoId, $fecha, $fecha]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Genera snapshot de parámetros para guardar al confirmar
     */
    public function generarSnapshot(array $item, array $empresa): array
    {
        return [
            'empresa_cuit'        => $empresa['cuit'] ?? '',
            'empresa_razon'       => $empresa['razon_social'] ?? '',
            'condicion_fiscal'    => $item['condicion_fiscal'],
            'alicuota'            => $item['alicuota_aplicada'],
            'minimo_no_sujeto'    => $item['minimo_no_sujeto'],
            'base_calculo'        => $item['base_calculo'],
            'base_sujeta'         => $item['base_sujeta'],
            'importe_retencion'   => $item['importe_retencion'],
            'fecha_calculo'       => date('Y-m-d H:i:s'),
        ];
    }
}
