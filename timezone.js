(function () {
    'use strict';

    function formatLocalDateTime(element) {
        const isoDate = element.getAttribute('datetime');
        const date = new Date(isoDate);
        if (Number.isNaN(date.getTime())) {
            return;
        }

        try {
            const userTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            element.textContent = new Intl.DateTimeFormat('es-AR', {
                dateStyle: 'medium',
                timeStyle: 'short',
                timeZoneName: 'short', // <-- Agrega la zona horaria (ej: GMT-3)
                timeZone: userTimeZone
            }).format(date);
        } catch (error) {
            element.textContent = date.toLocaleString();
        }
    }

    function formatAllDates() {
        document.querySelectorAll('.local-datetime[datetime]').forEach(formatLocalDateTime);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', formatAllDates);
    } else {
        formatAllDates();
    }
}());