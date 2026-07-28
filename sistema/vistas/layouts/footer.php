<?php // sistema/vistas/layouts/footer.php ?>
    </main><!-- /contenido -->

    <footer class="site-footer">
        MediTurnos &copy; <?= date('Y') ?> — Sistema de Gestión de Turnos Médicos
    </footer>
</div><!-- /main-wrap -->
</div><!-- /layout -->

<!--
El JS de modales/sidebar vive ACÁ (al final del layout, cargado por
TODA vista que incluya footer.php) y no en assets/js/, porque es
comportamiento genérico que cualquier pantalla puede necesitar
(cualquier vista puede traer un [data-modal] o un .modal-overlay);
ponerlo en un <script src> aparte solo sumaría una petición HTTP más
sin necesidad, ya que este bloque siempre se ejecuta de todos modos.
calendario.js y modal_turno.js, en cambio, sí son archivos aparte
porque son pesados y específicos de UNA sola pantalla (el calendario
de reserva del paciente): cargarlos acá los bajaría en TODAS las
páginas del sistema aunque no se usen.
-->
<script>
// Toggle sidebar en mobile
const sidebar   = document.getElementById('sidebar');
const btnToggle = document.getElementById('btnToggleSidebar');
if (btnToggle) {
    btnToggle.style.display = '';
    btnToggle.addEventListener('click', () => sidebar.classList.toggle('abierta'));
    document.addEventListener('click', (e) => {
        if (!sidebar.contains(e.target) && e.target !== btnToggle) {
            sidebar.classList.remove('abierta');
        }
    });
}

// Cerrar modales con Escape
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.abierto').forEach(m => {
            m.classList.remove('abierto');
        });
    }
});

// Botones de abrir/cerrar modales genéricos
document.querySelectorAll('[data-modal]').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.modal;
        const overlay = document.getElementById(id);
        if (overlay) overlay.classList.add('abierto');
    });
});
document.querySelectorAll('.btn-cerrar-modal').forEach(btn => {
    btn.addEventListener('click', () => {
        btn.closest('.modal-overlay').classList.remove('abierto');
    });
});
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) overlay.classList.remove('abierto');
    });
});
</script>
</body>
</html>
