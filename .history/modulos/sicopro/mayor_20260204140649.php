<!DOCTYPE html>
<html lang="es">
<head>
    <title>Mayor Contable - SICOPRO</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
</head>
<body>

<div class="container">
    <h2>Consulta de Mayor Contable</h2>
    
    <form method="POST" action="">
        <input type="number" name="anio" placeholder="Ejercicio (MOVEJER)" required>
        <input type="text" name="cuenta" placeholder="Cuenta" required>
        <input type="text" name="subcuenta" placeholder="Subcuenta" required>
        <input type="text" name="expediente" placeholder="Expediente (Opcional)">
        <input type="text" name="alcance" placeholder="Alcance (Opcional)">
        <button type="submit" name="consultar">Consultar</button>
    </form>

    <hr>

    <table id="tablaMayor" class="display" style="width:100%">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Expediente</th>
                <th>Alcance</th>
                <th>Descripción/Proveedor</th>
                <th>Debe</th>
                <th>Haber</th>
                <th>Saldo</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if (isset($_POST['consultar'])) {
                // Configuración de conexión (ajusta a tus variables)
                $conn = new mysqli("localhost", "usuario", "password", "tu_base");

                // Escapar entradas
                $anio = $_POST['anio'];
                $cta = $_POST['cuenta'];
                $scta = $_POST['subcuenta'];
                $exp = $_POST['expediente'];
                $alc = $_POST['alcance'];

                // Construcción de filtros opcionales
                $filtro_extra = "";
                if (!empty($exp)) $filtro_extra .= " AND MOVEXPE = '$exp'";
                if (!empty($alc)) $filtro_extra .= " AND MOVALEX = '$alc'";

                $sql = "
                SELECT movfeop, MOVEXPE, MOVALEX, MOVPROV, SUM(DEBE) as DEBE, SUM(HABER) as HABER
                FROM (
                    SELECT movfeop, MOVEXPE, MOVALEX, MOVPROV, MOVIMPO AS DEBE, 0 AS HABER
                    FROM sicopro_principal
                    WHERE MOVEJER = '$anio' AND MOVNCDE = '$cta' AND MOVSCDE = '$scta' $filtro_extra
                    
                    UNION ALL
                    
                    SELECT movfeop, MOVEXPE, MOVALEX, MOVPROV, 0 AS DEBE, MOVIMPO AS HABER
                    FROM sicopro_principal
                    WHERE MOVEJER = '$anio' AND MOVNCCR = '$cta' AND MOVSCCR = '$scta' $filtro_extra
                ) AS mayor
                GROUP BY movfeop, MOVEXPE, MOVALEX, MOVPROV
                ORDER BY movfeop ASC";

                $res = $conn->query($sql);
                $saldo_acumulado = 0;

                while ($row = $res->fetch_assoc()) {
                    $saldo_acumulado += ($row['DEBE'] - $row['HABER']);
                    echo "<tr>
                            <td>{$row['movfeop']}</td>
                            <td>{$row['MOVEXPE']}</td>
                            <td>{$row['MOVALEX']}</td>
                            <td>{$row['MOVPROV']}</td>
                            <td>" . number_format($row['DEBE'], 2) . "</td>
                            <td>" . number_format($row['HABER'], 2) . "</td>
                            <td>" . number_format($saldo_acumulado, 2) . "</td>
                          </tr>";
                }
            }
            ?>
        </tbody>
    </table>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

<script>
$(document).ready(function() {
    $('#tablaMayor').DataTable({
        dom: 'Bfrtip',
        buttons: ['excelHtml5'],
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
        }
    });
});
</script>

</body>
</html>