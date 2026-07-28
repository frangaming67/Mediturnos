<?php
// =============================================================
// sistema/vistas/componentes/paginador.php — Paginador reutilizable
// =============================================================
// Componente compartido por todos los listados largos (pacientes,
// usuarios, …). Se hizo genérico —en vez de repetir los <a> en cada
// vista— para que el paginado se vea y se comporte igual en todo el
// sistema y se corrija en un solo lugar.
//
// Espera definidas ANTES del require:
//   $pagina        (int) página actual
//   $totalPaginas  (int) cantidad total de páginas
//   $total         (int) cantidad total de registros
//
// Conserva los filtros activos de la URL: si el usuario filtró por
// apellido y pasa a la página 2, el filtro sigue aplicado.
// =============================================================

if (($totalPaginas ?? 1) <= 1) {
    return;   // una sola página: no tiene sentido mostrar controles
}

/** Arma la URL de una página conservando los demás parámetros GET. */
$urlPagina = function (int $n): string {
    $q = $_GET;
    $q['pagina'] = $n;
    return '?' . http_build_query($q);
};

// Ventana de páginas alrededor de la actual (no listar 40 números)
$desde = max(1, $pagina - 2);
$hasta = min($totalPaginas, $pagina + 2);
?>
<nav class="paginador" aria-label="Paginación de resultados">
    <span class="paginador-info">
        Página <?= (int) $pagina ?> de <?= (int) $totalPaginas ?>
        <span class="texto-gris">· <?= (int) $total ?> registro<?= $total === 1 ? '' : 's' ?></span>
    </span>

    <div class="paginador-controles">
        <?php if ($pagina > 1): ?>
            <a class="pag-btn" href="<?= htmlspecialchars($urlPagina($pagina - 1)) ?>" rel="prev" aria-label="Página anterior">‹</a>
        <?php else: ?>
            <span class="pag-btn deshabilitado" aria-hidden="true">‹</span>
        <?php endif; ?>

        <?php if ($desde > 1): ?>
            <a class="pag-btn" href="<?= htmlspecialchars($urlPagina(1)) ?>">1</a>
            <?php if ($desde > 2): ?><span class="pag-puntos">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $desde; $i <= $hasta; $i++): ?>
            <?php if ($i === (int) $pagina): ?>
                <span class="pag-btn activo" aria-current="page"><?= $i ?></span>
            <?php else: ?>
                <a class="pag-btn" href="<?= htmlspecialchars($urlPagina($i)) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($hasta < $totalPaginas): ?>
            <?php if ($hasta < $totalPaginas - 1): ?><span class="pag-puntos">…</span><?php endif; ?>
            <a class="pag-btn" href="<?= htmlspecialchars($urlPagina($totalPaginas)) ?>"><?= (int) $totalPaginas ?></a>
        <?php endif; ?>

        <?php if ($pagina < $totalPaginas): ?>
            <a class="pag-btn" href="<?= htmlspecialchars($urlPagina($pagina + 1)) ?>" rel="next" aria-label="Página siguiente">›</a>
        <?php else: ?>
            <span class="pag-btn deshabilitado" aria-hidden="true">›</span>
        <?php endif; ?>
    </div>
</nav>
