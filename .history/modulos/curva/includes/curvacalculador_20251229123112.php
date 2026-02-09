<?php
// archivo: includes/CurvaCalculator.php

class CurvaManager {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function guardarVersion($obraId, $datosForm) {
        try {
            $this->pdo->beginTransaction();

            // 1. Crear cabecera de versión
            // Desactivar versiones anteriores
            $stmt = $this->pdo->prepare("UPDATE curva_version SET es_vigente = 0 WHERE obra_id = ?");
            $stmt->execute([$obraId]);

            // Obtener siguiente número de versión
            $stmt = $this->pdo->prepare("SELECT MAX(nro_version) FROM curva_version WHERE obra_id = ?");
            $stmt->execute([$obraId]);
            $nextVersion = ((int)$stmt->fetchColumn()) + 1;

            // Insertar nueva versión
            $sqlVer = "INSERT INTO curva_version (obra_id, nro_version, motivo, modo, fecha_desde, fecha_hasta, es_vigente, created_at) 
                       VALUES (?, ?, ?, 'MANUAL', ?, ?, 1, NOW())";
            
            // Calculamos fechas min y max del array de items
            $primeraFecha = $datosForm['items'][0]['periodo'] . '-01';
            $ultimaItem = end($datosForm['items']);
            $ultimaFecha = date("Y-m-t", strtotime($ultimaItem['periodo'] . '-01'));

            $stmt = $this->pdo->prepare($sqlVer);
            $stmt->execute([
                $obraId, 
                $nextVersion, 
                "Generación inicial / Proyección FRI", 
                $primeraFecha, 
                $ultimaFecha
            ]);
            
            $versionId = $this->pdo->lastInsertId();

            // 2. Insertar Items
            $sqlItem = "INSERT INTO curva_items 
                (curva_version_id, periodo, porcentaje_plan, monto_bruto_plan, 
                 fri_proyectado, monto_redet_plan, 
                 anticipo_pago_plan, anticipo_recupero_plan, monto_neto_plan) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmtItem = $this->pdo->prepare($sqlItem);

            foreach ($datosForm['items'] as $item) {
                $stmtItem->execute([
                    $versionId,
                    $item['periodo'],
                    $item['pct'],           // Viene del input name="items[x][pct]"
                    $item['bruto'],         // Viene del input name="items[x][bruto]"
                    $item['fri'],           // Viene del input name="items[x][fri]"
                    $item['redet'],         // Viene del input name="items[x][redet]"
                    0,                      // Anticipo pago (se maneja aparte o es 0 en distribución normal)
                    $item['recupero'],      // Viene del input name="items[x][recupero]"
                    $item['neto']           // Viene del input name="items[x][neto]"
                ]);
            }

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
?>