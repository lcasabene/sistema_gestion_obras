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

            // 1. Desactivar versiones anteriores (Header)
            $stmt = $this->pdo->prepare("UPDATE curva_version SET es_vigente = 0 WHERE obra_id = ?");
            $stmt->execute([$obraId]);

            // 2. Obtener siguiente número de versión
            $stmt = $this->pdo->prepare("SELECT MAX(nro_version) FROM curva_version WHERE obra_id = ?");
            $stmt->execute([$obraId]);
            $nextVersion = ((int)$stmt->fetchColumn()) + 1;

            // 3. Insertar nueva versión (Header)
            // Calculamos fechas min y max
            $primeraFecha = $datosForm['items'][0]['periodo'] . '-01';
            $ultimaItem = end($datosForm['items']);
            $ultimaFecha = date("Y-m-t", strtotime($ultimaItem['periodo'] . '-01'));

            $sqlVer = "INSERT INTO curva_version 
                       (obra_id, nro_version, motivo, modo, fecha_desde, fecha_hasta, es_vigente, created_at) 
                       VALUES (?, ?, ?, 'MANUAL', ?, ?, 1, NOW())";
            
            $stmt = $this->pdo->prepare($sqlVer);
            $stmt->execute([
                $obraId, 
                $nextVersion, 
                "Generación inicial con proyección FRI", 
                $primeraFecha, 
                $ultimaFecha
            ]);
            
            $versionId = $this->pdo->lastInsertId();

            // 4. Insertar Items (ADAPTADO A TU TABLA DE LA IMAGEN)
            $sqlItem = "INSERT INTO curva_detalle (
                curva_version_id, 
                periodo, 
                porcentaje_plan, 
                monto_bruto_plan, 
                fri_proyectado,         -- Campo nuevo agregado
                monto_redet_plan,       -- Campo nuevo agregado
                anticipo_pago_plan, 
                anticipo_pago_modo, 
                anticipo_pago_fuente_id,
                anticipo_recupero_plan, 
                anticipo_rec_modo, 
                anticipo_rec_fuente_id,
                monto_neto_plan,
                monto_plan              -- Total final
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmtItem = $this->pdo->prepare($sqlItem);

            foreach ($datosForm['items'] as $item) {
                // Valores por defecto para tus ENUM y claves foráneas nulas
                $modoPago = 'FUFI';         // Valor default según tu imagen
                $modoRecupero = 'PARIPASSU';// Valor default según tu imagen
                $fuenteId = null;           // Null según tu imagen
                
                // El monto_plan suele ser el neto a pagar (o bruto + redet - recupero)
                // Aquí asumimos que es igual al monto_neto_plan calculado en el form
                $montoPlan = $item['neto']; 

                $stmtItem->execute([
                    $versionId,
                    $item['periodo'],       // char(7)
                    $item['pct'],           // decimal(6,3)
                    $item['bruto'],         // decimal(18,2)
                    $item['fri'],           // decimal(10,4) -> FRI Proyectado
                    $item['redet'],         // decimal(18,2) -> Monto Redeterminado
                    0.00,                   // anticipo_pago_plan (En curva de avance suele ser 0, el anticipo es financiero inicial)
                    $modoPago,              // enum
                    $fuenteId,              // int null
                    $item['recupero'],      // decimal(18,2)
                    $modoRecupero,          // enum
                    $fuenteId,              // int null
                    $item['neto'],          // decimal(18,2)
                    $montoPlan              // decimal(18,2)
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