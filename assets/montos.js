/* ==============================
   FORMATEO DE MONTOS - ARGENTINA
   ============================== */

function formatMonto(value) {
    value = value.replace(/\D/g, '');
    if (value === '') return '';
    let number = parseFloat(value) / 100;
    return number.toLocaleString('es-AR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function unformatMonto(value) {
    if (!value) return 0;
    return parseFloat(
        value.replace(/\./g, '').replace(',', '.')
    ) || 0;
}

/* ==============================
   MÁSCARA AUTOMÁTICA
   ============================== */
function bindMontoMask(selector = '.monto') {
    document.querySelectorAll(selector).forEach(input => {
        input.addEventListener('input', function () {
            let cursor = this.selectionStart;
            let oldLength = this.value.length;

            this.value = formatMonto(this.value);

            let newLength = this.value.length;
            this.selectionEnd = cursor + (newLength - oldLength);
        });
    });
}
