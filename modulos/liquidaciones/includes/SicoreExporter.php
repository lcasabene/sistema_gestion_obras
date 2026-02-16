<?php
/**
 * Exportador SICORE - Layout Posicional
 * Genera archivo TXT con formato posicional según especificación AFIP
 * 
 * Layout SICORE Retenciones:
 * Pos 1-2:   Código comprobante (07=Retención, 08=Nota Crédito Ret.)
 * Pos 3-8:   Fecha comprobante (DD/MM/AAAA -> DDMMAAAA en 8 pos)
 * Pos 9-13:  Número comprobante (5 dígitos, 0-padded)
 * Pos 14-33: Importe comprobante (16 enteros + 2 decimales, sin punto)
 * Pos 34-36: Código impuesto (3 dígitos)
 * Pos 37-39: Código régimen (3 dígitos)
 * Pos 40-40: Código operación (1=Retención)
 * Pos 41-57: Base de cálculo (13 enteros + 2 decimales + signo)
 * Pos 58-63: Fecha retención (DDMMAA)
 * Pos 64-64: Código condición (1=Inscripto, 2=No inscripto)
 * Pos 65-65: Retención practicada a sujetos suspendidos (0=No)
 * Pos 66-82: Importe retención (13 enteros + 2 decimales + signo)
 * Pos 83-94: Porcentaje exclusión (3 enteros + 2 decimales)
 * Pos 95-105: Fecha publicación BO (DDMMAAAA o espacios)
 * Pos 106-106: Tipo documento retenido (80=CUIT)
 * Pos 107-117: Número documento (CUIT 11 dígitos)
 * Pos 118-147: Número certificado original (30 chars, texto)
 */
class SicoreExporter
{
    private PDO $pdo;
    private string $codImpuestoDefault = '217';
    private string $codRegimenDefault = '830';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Genera archivo SICORE para un rango de fechas
     * @return array ['contenido' => string, 'cantidad' => int, 'importe_total' => float]
     */
    public function exportar(string $fechaDesde, string $fechaHasta, ?string $codImpuesto = null, ?string $codRegimen = null): array
    {
        $codImp = $codImpuesto ?? $this->codImpuestoDefault;
        $codReg = $codRegimen ?? $this->codRegimenDefault;

        // Obtener liquidaciones confirmadas con sus items
        $sql = "
            SELECT 
                l.id AS liq_id,
                l.comprobante_fecha,
                l.comprobante_numero,
                l.comprobante_importe_total,
                l.fecha_pago,
                l.nro_certificado_retencion,
                e.cuit AS empresa_cuit,
                e.razon_social,
                li.id AS item_id,
                li.impuesto,
                li.base_calculo,
                li.alicuota_aplicada,
                li.importe_retencion,
                li.condicion_fiscal,
                COALESCE(li.sicore_cod_impuesto, :cod_imp) AS cod_impuesto,
                COALESCE(li.sicore_cod_regimen, :cod_reg) AS cod_regimen,
                COALESCE(li.sicore_cod_comprobante, '07') AS cod_comprobante
            FROM liquidaciones l
            JOIN empresas e ON e.id = l.empresa_id
            JOIN liquidacion_items li ON li.liquidacion_id = l.id AND li.activo = 1
            WHERE l.estado = 'CONFIRMADO'
              AND l.fecha_pago BETWEEN :desde AND :hasta
            ORDER BY l.fecha_pago ASC, l.id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':cod_imp' => $codImp,
            ':cod_reg' => $codReg,
            ':desde'   => $fechaDesde,
            ':hasta'   => $fechaHasta,
        ]);
        $rows = $stmt->fetchAll();

        $lineas = [];
        $importeTotal = 0;
        $itemIds = [];

        foreach ($rows as $row) {
            $lineas[] = $this->generarLinea($row);
            $importeTotal += (float)$row['importe_retencion'];
            $itemIds[] = [
                'liquidacion_id'      => $row['liq_id'],
                'liquidacion_item_id' => $row['item_id'],
            ];
        }

        return [
            'contenido'      => implode("\r\n", $lineas),
            'cantidad'       => count($lineas),
            'importe_total'  => round($importeTotal, 2),
            'item_ids'       => $itemIds,
        ];
    }

    /**
     * Genera una línea SICORE posicional
     */
    private function generarLinea(array $row): string
    {
        $linea = '';

        // Pos 1-2: Código comprobante (2)
        $linea .= str_pad($row['cod_comprobante'], 2, '0', STR_PAD_LEFT);

        // Pos 3-10: Fecha comprobante DDMMAAAA (8)
        $linea .= date('dmY', strtotime($row['comprobante_fecha']));

        // Pos 11-15: Número comprobante (5)
        $numComp = preg_replace('/[^0-9]/', '', $row['comprobante_numero'] ?? '0');
        $linea .= str_pad(substr($numComp, -5), 5, '0', STR_PAD_LEFT);

        // Pos 16-31: Importe comprobante (16: 14 enteros + 2 decimales, sin punto)
        $linea .= $this->formatImporte($row['comprobante_importe_total'], 16);

        // Pos 32-34: Código impuesto (3)
        $linea .= str_pad($row['cod_impuesto'], 3, '0', STR_PAD_LEFT);

        // Pos 35-37: Código régimen (3)
        $linea .= str_pad($row['cod_regimen'], 3, '0', STR_PAD_LEFT);

        // Pos 38: Código operación (1)
        $linea .= '1'; // 1 = Retención

        // Pos 39-55: Base de cálculo (17: 14 enteros + 2 decimales + signo implícito)
        $linea .= $this->formatImporte($row['base_calculo'], 16);
        $linea .= ((float)$row['base_calculo'] >= 0) ? '0' : '1'; // Signo

        // Pos 56-63: Fecha retención DDMMAAAA (8)
        $linea .= date('dmY', strtotime($row['fecha_pago']));

        // Pos 64: Código condición (1)
        $linea .= ($row['condicion_fiscal'] === 'INSCRIPTO') ? '1' : '2';

        // Pos 65: Retención a sujetos suspendidos (1)
        $linea .= '0';

        // Pos 66-82: Importe retención (16 + signo)
        $linea .= $this->formatImporte(abs((float)$row['importe_retencion']), 16);
        $linea .= ((float)$row['importe_retencion'] >= 0) ? '0' : '1';

        // Pos 83-87: Porcentaje exclusión (5: 3+2)
        $linea .= '00000';

        // Pos 88-95: Fecha publicación BO (8 o espacios)
        $linea .= '        '; // 8 espacios

        // Pos 96-97: Tipo documento retenido (2)
        $linea .= '80'; // 80 = CUIT

        // Pos 98-108: Número CUIT (11)
        $cuit = preg_replace('/[^0-9]/', '', $row['empresa_cuit'] ?? '');
        $linea .= str_pad($cuit, 11, '0', STR_PAD_LEFT);

        // Pos 109-138: Número certificado original (30)
        $nroCert = $row['nro_certificado_retencion'] ?? '';
        $linea .= str_pad(substr($nroCert, 0, 30), 30, ' ', STR_PAD_RIGHT);

        return $linea;
    }

    /**
     * Formatea importe para SICORE: sin punto decimal, con padding de ceros
     */
    private function formatImporte(float $valor, int $longitud): string
    {
        $valorAbs = abs($valor);
        // Convertir a centavos (2 decimales)
        $centavos = (int)round($valorAbs * 100);
        return str_pad((string)$centavos, $longitud, '0', STR_PAD_LEFT);
    }

    /**
     * Valida longitud total de una línea
     */
    public function validarLinea(string $linea): bool
    {
        // La longitud estándar SICORE es variable según versión
        // Mínimo ~138 caracteres
        return strlen($linea) >= 130;
    }
}
