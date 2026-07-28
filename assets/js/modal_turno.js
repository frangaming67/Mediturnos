// =============================================================
// MediTurnos — Modal de confirmación de turno (vista del paciente)
// =============================================================
// Llena y abre el modal "Confirmar turno" cuando el paciente elige un
// horario. El cierre del modal (botón ✕, click fuera, tecla Escape) lo
// maneja el JS genérico de layouts/footer.php, así que acá solo va la
// apertura.
//
// Depende de variables globales definidas en calendario.js (cargado
// antes): matriculaSeleccionada.
//
// Vive en un archivo aparte de calendario.js (aunque ambos son piezas
// del mismo flujo de reserva) porque resuelven problemas distintos:
// calendario.js es SELECCIÓN (elegir médico/fecha/hora navegando por
// fetch), este archivo es solo VOLCADO DE DATOS (tomar el slot ya
// elegido y escribirlo en los inputs ocultos del formulario). Separarlos
// deja calendario.js enfocado en la lógica de calendario sin mezclar el
// detalle de qué IDs de input tiene el modal de confirmación.
// =============================================================

// ── Abrir modal de confirmación ─────────────────────
function abrirModal(slot, fecha) {
    const card = document.querySelector('.medico-card.seleccionado');
    const nombreMedico = card ? card.querySelector('.medico-nombre').textContent : '';
    const [anio, mes, dia] = fecha.split('-');

    document.getElementById('inp-matricula').value    = matriculaSeleccionada;
    document.getElementById('inp-fecha').value        = fecha;
    document.getElementById('inp-hora').value         = slot.hora_full || slot.hora;
    document.getElementById('inp-especialidad').value = slot.id_especialidad;
    document.getElementById('inp-consultorio').value  = slot.id_consultorio;

    document.getElementById('conf-medico').textContent      = nombreMedico;
    document.getElementById('conf-especialidad').textContent = slot.especialidad;
    document.getElementById('conf-fecha').textContent       = `${dia}/${mes}/${anio}`;
    document.getElementById('conf-hora').textContent        = slot.hora;
    document.getElementById('conf-consultorio').textContent = slot.consultorio;

    document.getElementById('modal-turno').classList.add('abierto');
}
