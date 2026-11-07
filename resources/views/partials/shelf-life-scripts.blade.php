<script>
window.updateShelfLifeExpiry = function () {
    var production = document.getElementById('fProduction');
    var expiry = document.getElementById('fExpiry');
    if (!production || !expiry || !production.value) { return; }
    var parts = production.value.split('-').map(Number);
    var date = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));
    var months = Number(production.dataset.months || 0);
    var days = Number(production.dataset.days || 0);
    if (months) {
        date.setUTCDate(1);
        date.setUTCMonth(date.getUTCMonth() + months);
        var lastDay = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth() + 1, 0)).getUTCDate();
        date.setUTCDate(Math.min(parts[2], lastDay));
    } else if (days) {
        date.setUTCDate(date.getUTCDate() + days);
    } else { return; }
    expiry.value = date.toISOString().slice(0, 10);
};
</script>
