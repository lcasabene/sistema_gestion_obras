<?php
/**
 * Exportador SIRE – F2004 (191 caracteres por línea)
 * Genera archivo TXT con formato posicional fijo según especificación AFIP/SIRE
 *
 * Layout F2004 Retenciones (191 caracteres):
 * Pos 001-002: Código comprobante (2) - "06" Recibo / "07" Retención
 * Pos 003-010: Fecha comprobante DD/MM/AAAA → DDMMAAAA (8)
 * Pos 011-015: Punto de venta (5, numérico 0-padded)
 * Pos 016-035: Número comprobante (20, numérico 0-padded)
 * Pos 036-051: Importe comprobante (16: 13 enteros + , + 2 decimales)
 * Pos 052-054: Código impuesto (3)
 * Pos 055-057: Código régimen (3)
 * Pos 058-058: Código operación (1) "1"=Retención
 * Pos 059-074: Base de cálculo (16: 13+,+2)
 * Pos 075-082: Fecha emisión retención DD/MM/AAAA → DDMMAAAA (8)
 * Pos 083-084: Código condición (2) "01"=Inscripto "02"=No inscripto
 * Pos 085-085: Retención pract. a sujetos suspendidos (1) "0"=No
 * Pos 086-101: Importe retención (16: 13+,+2)
 * Pos 102-107: Porcentaje exclusión (6: 3+,+2)
 * Pos 108-117: Fecha publicación BO DDMMAAAA (10 o espacios)
 * Pos 118-119: Tipo documento retenido (2) "80"=CUIT
 * Pos 120-130: Número CUIT (11)
 * Pos 131-140: Número certificado original (10)
 * Pos 141-141: Tipo documento ordenante (1) "0"
 * Pos 142-142: Denominación ordenante - Tipo (1) "0"
 * Pos 143-173: Denominación ordenante (31, texto, spaces)
 * Pos 174-174: Acrecentamiento (1) "0"
 * Pos 175-190: Importe certificado original (16: 13+,+2)
 * Pos 191-191: Signo (1) "0"=Positivo "1"=Negativo
 */
class SireExporter
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Genera archivo SIRE F2004 para un rango de fechas
     */
    public function exportar(string $fechaDesde, string $fechaHasta): array
    {
        $sql = "
            SELECT 
                l.id AS liq_id,
                l.comprobante_tipo,
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
                COALESCE(li.sicore_cod_impuesto, '217') AS cod_impuesto,
                COALESCE(li.sicore_cod_regimen, '830') AS cod_regimen,
                COALESCE(li.sicore_cod_comprobante, '07') AS cod_comprobante
            FROM liquidaciones l
            JOIN empresas e ON e.id = l.empresa_id
            JOIN liquidacion_items li ON li.liquidacion_id = l.id AND li.activo = 1
            WHERE l.estado = 'CONFIRMADO'
              AND l.fecha_pago BETWEEN :desde AND :hasta
            ORDER BY l.fecha_pago ASC, l.id ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':desde' => $fechaDesde, ':hasta' => $fechaHasta]);
        $rows = $stmt->fetchAll();

        $lineas = [];
        $importeTotal = 0;
        $itemIds = [];

        foreach ($rows as $row) {
            $linea = $this->generarLinea($row);
            if ($this->validarLinea($linea)) {
                $lineas[] = $linea;
                $importeTotal += abs((float)$row['importe_retencion']);
                $itemIds[] = [
                    'liquidacion_id'      => $row['liq_id'],
                    'liquidacion_item_id' => $row['item_id'],
                ];
            }
        }

        return [
            'contenido'     => implode("\r\n", $lineas),
            'cantidad'      => count($lineas),
            'importe_total' => round($importeTotal, 2),
            'item_ids'      => $itemIds,
        ];
    }

    /**
     * Genera una línea SIRE F2004 de exactamente 191 caracteres
     */
    private function generarLinea(array $row): string
    {
        $linea = '';

        // Pos 001-002: Código comprobante (2)
        $linea .= str_pad($row['cod_comprobante'], 2, '0', STR_PAD_LEFT);

        // Pos 003-010: Fecha comprobante DDMMAAAA (8)
        $linea .= date('dmY', strtotime($row['comprobante_fecha']));

        // Pos 011-015: Punto de venta (5)
        $ptoVta = '00000';
        if (!empty($row['comprobante_numero'])) {
            $partes = explode('-', $row['comprobante_numero']);
            if (count($partes) >= 2) {
                $ptoVta = str_pad(preg_replace('/[^0-9]/', '', $partes[0]), 5, '0', STR_PAD_LEFT);
            }
        }
        $linea .= $ptoVta;

        // Pos 016-035: Número comprobante (20)
        $numComp = preg_replace('/[^0-9]/', '', $row['comprobante_numero'] ?? '0');
        $linea .= str_pad(substr($numComp, -20), 20, '0', STR_PAD_LEFT);

        // Pos 036-051: Importe comprobante (16)
        $linea .= $this->formatImporteSire((float)$row['comprobante_importe_total'], 16);

        // Pos 052-054: Código impuesto (3)
        $linea .= str_pad($row['cod_impuesto'], 3, '0', STR_PAD_LEFT);

        // Pos 055-057: Código régimen (3)
        $linea .= str_pad($row['cod_regimen'], 3, '0', STR_PAD_LEFT);

        // Pos 058: Código operación (1)
        $linea .= '1';

        // Pos 059-074: Base de cálculo (16)
        $linea .= $this->formatImporteSire((float)$row['base_calculo'], 16);

        // Pos 075-082: Fecha emisión retención DDMMAAAA (8)
        $linea .= date('dmY', strtotime($row['fecha_pago']));

        // Pos 083-084: Código condición (2)
        $linea .= ($row['condicion_fiscal'] === 'INSCRIPTO') ? '01' : '02';

        // Pos 085: Retención a sujetos suspendidos (1)
        $linea .= '0';

        // Pos 086-101: Importe retención (16)
        $linea .= $this->formatImporteSire(abs((float)$row['importe_retencion']), 16);

        // Pos 102-107: Porcentaje exclusión (6)
        $linea .= '000,00';

        // Pos 108-117: Fecha publicación BO (10)
        $linea .= '          '; // 10 espacios

        // Pos 118-119: Tipo documento (2)
        $linea .= '80';

        // Pos 120-130: CUIT (11)
        $cuit = preg_replace('/[^0-9]/', '', $row['empresa_cuit'] ?? '');
        $linea .= str_pad($cuit, 11, '0', STR_PAD_LEFT);

        // Pos 131-140: Número certificado original (10)
        $nroCert = preg_replace('/[^0-9]/', '', $row['nro_certificado_retencion'] ?? '');
        $linea .= str_pad(substr($nroCert, 0, 10), 10, '0', STR_PAD_LEFT);

        // Pos 141: Tipo documento ordenante (1)
        $linea .= '0';

        // Pos 142: Denominación ordenante tipo (1)
        $linea .= '0';

        // Pos 143-173: Denominación ordenante (31)
        $linea .= str_pad('', 31, ' ', STR_PAD_RIGHT);

        // Pos 174: Acrecentamiento (1)
        $linea .= '0';

        // Pos 175-190: Importe certificado original (16)
        $linea .= $this->formatImporteSire(abs((float)$row['importe_retencion']), 16);

        // Pos 191: Signo (1)
        $linea .= ((float)$row['importe_retencion'] >= 0) ? '0' : '1';

        return $linea;
    }

    /**
     * Formatea importe para SIRE: 13 dígitos enteros + comma + 2 decimales = 16 chars
     * Ejemplo: 1234.56 → "0000000001234,56"
     */
    private function formatImporteSire(float $valor, int $longitud): string
    {
        $valorAbs = abs($valor);
        $parteEntera = (int)floor($valorAbs);
        $parteDecimal = (int)round(($valorAbs - $parteEntera) * 100);

        $enteroStr = str_pad((string)$parteEntera, $longitud - 3, '0', STR_PAD_LEFT);
        $decimalStr = str_pad((string)$parteDecimal, 2, '0', STR_PAD_LEFT);

        return $enteroStr . ',' . $decimalStr;
    }

    /**
     * Valida que la línea tenga exactamente 191 caracteres
     */
    public function validarLinea(string $linea): bool
    {
        return strlen($linea) === 191;
    }
}
