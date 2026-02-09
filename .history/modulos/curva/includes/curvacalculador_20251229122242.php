<?php
class CurvaCalculator {

    /**
     * Genera una proyección de curva S considerando vedas.
     * * @param float $montoTotal Monto base del contrato
     * @param string $fechaInicio 'Y-m-d'
     * @param int $duracionMeses Plazo de obra
     * @param float $pctAnticipo Porcentaje de anticipo (ej: 20)
     * @param array $mesesVeda Array de meses donde no se trabaja (ej: [6, 7] para Jun/Jul)
     */
    public static function generarPropuesta($montoTotal, $fechaInicio, $duracionMeses, $pctAnticipo, $mesesVeda = []) {
        
        $fechaActual = new DateTime($fechaInicio);
        // Ajustar al primer día del mes
        $fechaActual->modify('first day of this month');

        $items = [];
        $mesesLaborables = 0;
        $calendario = [];

        // 1. Armar calendario y detectar meses laborables
        for ($i = 0; $i < $duracionMeses; $i++) {
            $mes = (int)$fechaActual->format('n');
            $esVeda = in_array($mes, $mesesVeda);
            
            $periodo = $fechaActual->format('Y-m'); // Formato char(7)
            
            $calendario[] = [
                'periodo' => $periodo,
                'es_veda' => $esVeda,
                'fecha_obj' => clone $fechaActual
            ];

            if (!$esVeda) {
                $mesesLaborables++;
            }

            $fechaActual->modify('+1 month');
        }

        // Si todo es veda (raro), evitamos división por cero
        if ($mesesLaborables == 0) $mesesLaborables = 1;

        // 2. Distribuir porcentaje (Curva S Simplificada)
        // Usamos una función seno de 0 a PI para simular la campana/S
        // O simplemente lineal ponderada. Aquí un método de distribución normal simplificado.
        
        $acumulado = 0;
        $montoAnticipoTotal = $montoTotal * ($pctAnticipo / 100);
        
        // Iteramos de nuevo para asignar valores
        $contadorLaborable = 0;

        foreach ($calendario as $k => $cal) {
            if ($cal['es_veda']) {
                $avanceMes = 0;
            } else {
                $contadorLaborable++;
                // Algoritmo Curva S: (Normalizado de -3 a 3 desviaciones estándar aprox)
                // Usaremos una fórmula matemática simple: Sine Wave de -PI/2 a PI/2
                // Ojo: Esto es una aproximación para autocompletar, el usuario luego edita.
                
                // Opción simple: Distribución triangular suave
                // x va de 0 a 1
                $x = $contadorLaborable / $mesesLaborables; 
                
                // Fórmula Sigmoide simple: x^2 / (x^2 + (1-x)^2) da una curva S perfecta de 0 a 1
                $curvaS_actual = pow($x, 2) / (pow($x, 2) + pow(1 - $x, 2));
                
                // El avance del mes es la diferencia entre la S actual y la anterior
                $x_prev = ($contadorLaborable - 1) / $mesesLaborables;
                $curvaS_prev = ($contadorLaborable == 1) ? 0 : pow($x_prev, 2) / (pow($x_prev, 2) + pow(1 - $x_prev, 2));
                
                $avanceMes = ($curvaS_actual - $curvaS_prev) * 100;
            }

            // Cálculos monetarios
            $montoBruto = $montoTotal * ($avanceMes / 100);
            
            // Lógica de Recupero de Anticipo:
            // Se descuenta proporcionalmente al avance.
            // Si avanzo un 5% de obra, devuelvo el 5% del anticipo TOTAL otorgado.
            $recuperoAnticipo = $montoAnticipoTotal * ($avanceMes / 100);
            
            $montoNeto = $montoBruto - $recuperoAnticipo;

            $items[] = [
                'periodo' => $cal['periodo'],
                'es_veda' => $cal['es_veda'],
                'porcentaje_plan' => round($avanceMes, 4), // 4 decimales para precisión
                'monto_bruto_plan' => $montoBruto,
                'anticipo_recupero_plan' => $recuperoAnticipo,
                'monto_neto_plan' => $montoNeto
            ];
            
            $acumulado += $avanceMes;
        }

        // Ajuste fino por redondeo (para que cierre en 100%)
        if (!empty($items) && abs($acumulado - 100) > 0.0001) {
            $diff = 100 - $acumulado;
            // Sumar la diferencia al último mes laborable
            for ($z = count($items) - 1; $z >= 0; $z--) {
                if (!$items[$z]['es_veda']) {
                    $items[$z]['porcentaje_plan'] += $diff;
                    // Recalcular montos de ese mes...
                    $items[$z]['monto_bruto_plan'] = $montoTotal * ($items[$z]['porcentaje_plan'] / 100);
                    $items[$z]['anticipo_recupero_plan'] = $montoAnticipoTotal * ($items[$z]['porcentaje_plan'] / 100);
                    $items[$z]['monto_neto_plan'] = $items[$z]['monto_bruto_plan'] - $items[$z]['anticipo_recupero_plan'];
                    break;
                }
            }
        }

        return $items;
    }
}
?>