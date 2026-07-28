// =============================================================
// MediTurnos — Calendario de reserva (vista del paciente)
// =============================================================
// Maneja: selección de médico, navegación de meses, render del
// calendario y carga de horarios (slots) disponibles por fecha.
//
// Depende de la constante global BASE (URL base del proyecto), que
// dashboard_paciente.php define en un <script> inline ANTES de cargar
// este archivo. Al seleccionar un horario invoca abrirModal(), que
// vive en modal_turno.js (cargado a continuación).
//
// Es un archivo JS aparte (y no PHP embebido en dashboard_paciente.php)
// porque el calendario necesita repintarse muchas veces (cambiar de mes,
// elegir médico) SIN recargar la página completa; eso solo se puede
// hacer con JS corriendo en el navegador. El PHP en cambio arma UNA vez
// el HTML inicial (la lista de médicos) y después este script toma la
// posta pidiendo datos nuevos por fetch() cada vez que hace falta.
// =============================================================

const diasNombre  = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
const mesesNombre = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
const diasDB = ['Domingo','Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'];

let hoy         = new Date();
let mesActual   = hoy.getMonth();
let anioActual  = hoy.getFullYear();
let matriculaSeleccionada = null;
let diasTrabaja = [];   // e.g. ['Lunes','Jueves']
let fechasAusente = []; // e.g. ['2026-06-20']  días que el médico no atiende
let fechaSeleccionada = null;

// ── Seleccionar médico ──────────────────────────────
function seleccionarMedico(el) {
    document.querySelectorAll('.medico-card').forEach(c => c.classList.remove('seleccionado'));
    el.classList.add('seleccionado');
    matriculaSeleccionada = el.dataset.matricula;
    fechaSeleccionada = null;
    document.getElementById('slots-container').style.display = 'none';

    // Se piden en paralelo los días que trabaja y los días que está ausente
    // (dos fetch, no uno solo) porque son dos preguntas de negocio
    // distintas resueltas por consultas separadas en el controlador
    // (accion=horarios lee horario_atencion, accion=ausencias lee
    // ausencia_medico); fusionarlas en un tercer endpoint obligaría a
    // duplicar esa lógica o acoplar dos tablas que no tienen por qué
    // conocerse entre sí solo para conveniencia del front.
    Promise.all([
        fetch(`${BASE}sistema/controladores/ControladorTurno.php?accion=horarios&matricula=${matriculaSeleccionada}`).then(r => r.json()),
        fetch(`${BASE}sistema/controladores/ControladorTurno.php?accion=ausencias&matricula=${matriculaSeleccionada}`).then(r => r.json())
    ]).then(([dias, ausencias]) => {
        diasTrabaja   = dias;
        fechasAusente = ausencias;
        renderCalendario();
    });
}

// ── Navegación de mes ───────────────────────────────
function cambiarMes(dir) {
    mesActual += dir;
    if (mesActual > 11) { mesActual = 0;  anioActual++; }
    if (mesActual < 0)  { mesActual = 11; anioActual--; }
    fechaSeleccionada = null;
    document.getElementById('slots-container').style.display = 'none';
    renderCalendario();
}

// ── Renderizar calendario ───────────────────────────
function renderCalendario() {
    document.getElementById('cal-titulo').textContent =
        `${mesesNombre[mesActual]} ${anioActual}`;

    const grid = document.getElementById('cal-grid');
    grid.innerHTML = '';

    // Encabezado días
    diasNombre.forEach(d => {
        const h = document.createElement('div');
        h.className = 'cal-dia-nombre';
        h.textContent = d;
        grid.appendChild(h);
    });

    const primerDia = new Date(anioActual, mesActual, 1).getDay(); // 0=Dom
    const diasEnMes = new Date(anioActual, mesActual + 1, 0).getDate();

    // Espacios vacíos al inicio
    for (let i = 0; i < primerDia; i++) {
        const vacio = document.createElement('div');
        vacio.className = 'cal-dia otro-mes';
        grid.appendChild(vacio);
    }

    // Días del mes
    for (let d = 1; d <= diasEnMes; d++) {
        const fecha = new Date(anioActual, mesActual, d);
        const diaSemana = diasDB[fecha.getDay()];
        const fechaStr = `${anioActual}-${String(mesActual+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        const esHoy = fecha.toDateString() === hoy.toDateString();
        const esPasado = fecha < new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate());
        const esAusente = fechasAusente.includes(fechaStr); // el médico no atiende ese día
        const disponible = matriculaSeleccionada && diasTrabaja.includes(diaSemana) && !esPasado && !esAusente;

        const cell = document.createElement('div');
        cell.className = 'cal-dia';
        cell.textContent = d;

        if (esHoy)      cell.classList.add('hoy');
        if (esAusente && !esPasado) {
            cell.classList.add('ausente');
            cell.title = 'El médico no atiende este día';
        }
        if (!disponible) {
            // no clickeable
        } else {
            cell.classList.add('disponible');
            if (fechaStr === fechaSeleccionada) cell.classList.add('seleccionado');
            cell.onclick = () => seleccionarFecha(fechaStr, cell);
        }

        grid.appendChild(cell);
    }
}

// ── Seleccionar fecha y cargar slots ────────────────
function seleccionarFecha(fecha, cell) {
    fechaSeleccionada = fecha;
    document.querySelectorAll('.cal-dia.seleccionado')
            .forEach(c => c.classList.remove('seleccionado'));
    cell.classList.add('seleccionado');

    const slotsContainer = document.getElementById('slots-container');
    const slotsGrid      = document.getElementById('slots-grid');
    const slotsTitulo    = document.getElementById('slots-titulo');

    slotsGrid.innerHTML = '<span class="texto-cargando">Cargando...</span>';
    slotsContainer.style.display = 'block';

    fetch(`${BASE}sistema/controladores/ControladorTurno.php?accion=slots&matricula=${matriculaSeleccionada}&fecha=${fecha}`)
        .then(r => r.json())
        .then(slots => {
            const [anio, mes, dia] = fecha.split('-');
            slotsTitulo.textContent = `Horarios — ${dia}/${mes}/${anio}`;

            if (slots.length === 0) {
                slotsGrid.innerHTML = '<span class="texto-cargando">Sin turnos disponibles para este día.</span>';
                return;
            }

            slotsGrid.innerHTML = '';
            slots.forEach(s => {
                const btn = document.createElement('button');
                btn.className = 'slot-btn';
                btn.textContent = s.hora;
                btn.type = 'button';
                btn.onclick = () => abrirModal(s, fecha);
                slotsGrid.appendChild(btn);
            });
        });
}

// Inicializar calendario
renderCalendario();
