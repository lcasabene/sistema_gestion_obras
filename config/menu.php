<?php
/**
 * Configuración del menú principal - Panel de Gestión
 * 
 * Estructura:
 * - modules: array de módulos principales
 *   - key: clave única para permisos (usada en can_access_module())
 *   - title: título del módulo
 *   - icon: icono Bootstrap Icons
 *   - color: clase de color de Bootstrap (primary, success, danger, etc.) o código hex
 *   - columns: ancho en columnas (por defecto 4)
 *   - items: array de items del menú
 *     - label: texto visible
 *     - href: URL destino
 *     - icon: icono Bootstrap Icons (opcional)
 *     - badge: array con text y class (opcional)
 *     - requires: 'admin'|'edit' - requiere permiso adicional (opcional)
 *     - highlight: true para destacar visualmente (opcional)
 * 
 * Para agregar una nueva funcionalidad:
 * 1. Agregar el item al array correspondiente en 'items'
 * 2. Si es un módulo nuevo, agregar un nuevo módulo al array
 */

return [
    'modules' => [
        [
            'key' => 'obras',
            'title' => 'Gestión de Obras',
            'icon' => 'building-gear',
            'color' => 'primary',
            'columns' => 4,
            'items' => [
                [
                    'label' => 'Listado y Avance',
                    'href' => '../modulos/obras/obras_listado.php',
                    'icon' => 'list-check',
                    'icon_color' => 'primary',
                    'badge' => ['text' => 'Principal', 'class' => 'bg-light text-dark border']
                ],
                [
                    'label' => 'Certificados y Pagos',
                    'href' => '../modulos/certificados/certificados_listado.php',
                    'icon' => 'file-earmark-check',
                    'icon_color' => 'success'
                ],
                [
                    'label' => 'Empresas Contratistas',
                    'href' => '../modulos/empresas/empresas_listado.php',
                    'icon' => 'briefcase',
                    'icon_color' => 'secondary'
                ],
                [
                    'label' => 'Curvas de Inversión',
                    'href' => '../modulos/curva/curva_listado.php',
                    'icon' => 'graph-up',
                    'icon_color' => 'warning'
                ],
                [
                    'label' => 'Vedas y Paralizaciones',
                    'href' => '../modulos/vedas/vedas_listado.php',
                    'icon' => 'snow2',
                    'icon_color' => 'info'
                ]
            ]
        ],
        [
            'key' => 'liquidaciones',
            'title' => 'Liquidaciones – Impositiva',
            'icon' => 'calculator',
            'color' => 'danger',
            'columns' => 4,
            'items' => [
                [
                    'label' => 'Listado de Liquidaciones',
                    'href' => '../modulos/liquidaciones/liquidaciones_listado.php',
                    'icon' => 'cash-stack',
                    'icon_color' => 'danger',
                    'badge' => ['text' => 'Principal', 'class' => 'bg-light text-dark border']
                ],
                [
                    'label' => 'Nueva Liquidación',
                    'href' => '../modulos/liquidaciones/liquidacion_form.php',
                    'icon' => 'plus-circle',
                    'icon_color' => 'primary'
                ],
                [
                    'label' => 'Exportar SICORE',
                    'href' => '../modulos/liquidaciones/exportar_sicore.php',
                    'icon' => 'file-earmark-arrow-down',
                    'icon_color' => 'success'
                ],
                [
                    'label' => 'Exportar SIRE F2004',
                    'href' => '../modulos/liquidaciones/exportar_sire.php',
                    'icon' => 'file-earmark-arrow-down',
                    'icon_color' => 'info'
                ],
                [
                    'label' => 'Config RG 830',
                    'href' => '../modulos/liquidaciones/config/rg830_config.php',
                    'icon' => 'sliders',
                    'icon_color' => 'warning',
                    'requires' => 'admin',
                    'highlight' => true
                ]
            ]
        ],
        [
            'key' => 'presupuesto',
            'title' => 'Presupuesto',
            'icon' => 'cash-stack',
            'color' => 'success',
            'columns' => 4,
            'items' => [
                [
                    'label' => 'Partidas y Créditos',
                    'href' => '../modulos/presupuesto/presupuesto.php',
                    'icon' => 'layout-text-window',
                    'icon_color' => 'success'
                ],
                [
                    'label' => 'Importar Ejecución',
                    'href' => '../modulos/presupuesto/importar.php',
                    'icon' => 'upload',
                    'icon_color' => 'primary'
                ],
                [
                    'label' => 'Reporte Ejecución',
                    'href' => '../modulos/presupuesto/reporte_ejecucion.php',
                    'icon' => 'bar-chart',
                    'icon_color' => 'warning'
                ]
            ]
        ],
        [
            'key' => 'programas',
            'title' => 'Programas – Desembolsos / Rendiciones / Saldos',
            'icon' => 'diagram-3',
            'color' => '#198754', // Custom green
            'is_complex' => true, // Layout especial de 3 columnas
            'columns' => 12,
            'sections' => [
                [
                    'title' => 'Principal',
                    'column_class' => 'col-md-4',
                    'border_class' => 'border-end',
                    'items' => [
                        [
                            'label' => 'Dashboard / Resumen',
                            'href' => '../modulos/programas/dashboard.php',
                            'icon' => 'bar-chart-line',
                            'icon_color' => 'warning',
                            'badge' => ['text' => 'Dashboard', 'class' => 'bg-warning text-dark']
                        ],
                        [
                            'label' => 'Bancos Financiadores',
                            'href' => '../modulos/programas/bancos_listado.php',
                            'icon' => 'bank2',
                            'icon_color' => 'info',
                            'badge' => ['text' => 'BID / BM / CAF', 'class' => 'bg-info text-dark']
                        ],
                        [
                            'label' => 'Listado de Programas',
                            'href' => '../modulos/programas/index.php',
                            'icon' => 'grid-3x3-gap',
                            'icon_color' => 'success',
                            'badge' => ['text' => 'Principal', 'class' => 'bg-light text-dark border']
                        ],
                        [
                            'label' => 'Nuevo Programa',
                            'href' => '../modulos/programas/programa_form.php',
                            'icon' => 'plus-circle',
                            'icon_color' => 'primary',
                            'requires' => 'edit'
                        ]
                    ]
                ],
                [
                    'title' => 'Desembolsos / Rendiciones',
                    'title_icon' => 'arrow-down-circle',
                    'column_class' => 'col-md-4',
                    'border_class' => 'border-end',
                    'show_title' => true,
                    'items' => [
                        [
                            'label' => 'Ver Desembolsos por Programa',
                            'href' => '../modulos/programas/desembolsos_listado.php',
                            'icon' => 'cash-coin',
                            'icon_color' => 'info'
                        ],
                        [
                            'label' => 'Nuevo Desembolso',
                            'href' => '../modulos/programas/desembolso_form.php',
                            'icon' => 'plus-circle',
                            'icon_color' => 'info',
                            'requires' => 'edit'
                        ],
                        [
                            'label' => 'Ver Rendiciones por Programa',
                            'href' => '../modulos/programas/rendiciones_listado.php',
                            'icon' => 'clipboard-check',
                            'icon_color' => 'warning'
                        ],
                        [
                            'label' => 'Nueva Rendición',
                            'href' => '../modulos/programas/rendicion_form.php',
                            'icon' => 'plus-circle',
                            'icon_color' => 'warning',
                            'requires' => 'edit'
                        ]
                    ]
                ],
                [
                    'title' => 'Saldos / Pagos',
                    'title_icon' => 'bank',
                    'column_class' => 'col-md-4',
                    'show_title' => true,
                    'items' => [
                        [
                            'label' => 'Ver Saldos Bancarios',
                            'href' => '../modulos/programas/saldos_listado.php',
                            'icon' => 'bank',
                            'icon_color' => 'primary'
                        ],
                        [
                            'label' => 'Nuevo Saldo Bancario',
                            'href' => '../modulos/programas/saldo_form.php',
                            'icon' => 'plus-circle',
                            'icon_color' => 'primary',
                            'requires' => 'edit'
                        ],
                        [
                            'label' => 'Importar Pagos (Excel/CSV)',
                            'href' => '../modulos/programas/pagos_importar.php',
                            'icon' => 'upload',
                            'icon_color' => 'secondary'
                        ]
                    ]
                ]
            ]
        ],
        [
            'key' => 'arca',
            'title' => 'ARCA / AFIP',
            'icon' => 'cloud-download',
            'color' => '#6610f2', // Custom purple
            'columns' => 3,
            'items' => [
                [
                    'label' => 'Comprobantes',
                    'href' => '../modulos/arca/facturas_listado.php',
                    'icon' => 'file-earmark-spreadsheet',
                    'icon_color' => 'purple',
                    'badge' => ['text' => 'Principal', 'class' => 'bg-light text-dark border']
                ],
                [
                    'label' => 'Importar CSV',
                    'href' => '../modulos/arca/arca_import.php',
                    'icon' => 'upload',
                    'icon_color' => 'primary'
                ]
            ]
        ],
        [
            'key' => 'sicopro',
            'title' => 'SICOPRO',
            'icon' => 'database-fill-gear',
            'color' => 'info',
            'columns' => 5,
            'has_subsections' => true,
            'subsections' => [
                [
                    'title' => 'Anticipos',
                    'title_icon' => 'clock-history',
                    'items' => [
                        [
                            'label' => 'Total Anticipado',
                            'href' => '../modulos/sicopro/listado_anticipos.php?tipo=TOTAL_ANTICIPADO',
                            'icon' => 'table',
                            'icon_color' => 'info'
                        ],
                        [
                            'label' => 'Solicitado No Anticipado',
                            'href' => '../modulos/sicopro/listado_anticipos.php?tipo=SOLICITADO',
                            'icon' => 'table',
                            'icon_color' => 'info'
                        ],
                        [
                            'label' => 'Anticipado Sin Pago Prov.',
                            'href' => '../modulos/sicopro/listado_anticipos.php?tipo=SIN_PAGO',
                            'icon' => 'table',
                            'icon_color' => 'info'
                        ]
                    ]
                ],
                [
                    'title' => 'Consultas',
                    'title_icon' => 'search',
                    'items' => [
                        [
                            'label' => 'Base SICOPRO',
                            'href' => '../modulos/sicopro/listado_sicopro.php',
                            'icon' => 'database',
                            'icon_color' => 'secondary'
                        ],
                        [
                            'label' => 'Listado Liquidaciones',
                            'href' => '../modulos/sicopro/listado_liquidaciones.php',
                            'icon' => 'file-earmark-text',
                            'icon_color' => 'secondary'
                        ],
                        [
                            'label' => 'Listado Sigue',
                            'href' => '../modulos/sicopro/listado_sigue.php',
                            'icon' => 'file-earmark-text',
                            'icon_color' => 'secondary'
                        ]
                    ]
                ],
                [
                    'title' => 'Contable',
                    'title_icon' => 'journal-check',
                    'items' => [
                        [
                            'label' => 'Mayor Contable',
                            'href' => '../modulos/sicopro/mayor.php',
                            'icon' => 'book',
                            'icon_color' => 'dark'
                        ],
                        [
                            'label' => 'Importar Archivos',
                            'href' => '../modulos/sicopro/importar.php',
                            'icon' => 'cloud-upload-fill',
                            'icon_color' => 'primary',
                            'highlight' => true
                        ]
                    ]
                ]
            ]
        ],
        [
            'key' => 'configuracion',
            'title' => 'Configuración',
            'icon' => 'gear-fill',
            'color' => 'secondary',
            'columns' => 4,
            'always_show' => true, // No requiere permiso específico
            'has_subsections' => true,
            'subsections' => [
                [
                    'title' => 'Datos Maestros',
                    'title_icon' => 'collection',
                    'items' => [
                        [
                            'label' => 'Organismos Financiadores',
                            'href' => '../modulos/obras/organismos_listado.php',
                            'icon' => 'bank2',
                            'icon_color' => 'warning'
                        ],
                        [
                            'label' => 'Regiones',
                            'href' => '../modulos/obras/regiones_listado.php',
                            'icon' => 'geo-alt',
                            'icon_color' => 'success'
                        ],
                        [
                            'label' => 'Fuentes de Financiamiento',
                            'href' => '../modulos/fuentes/fuentes_listado.php',
                            'icon' => 'bank',
                            'icon_color' => 'primary'
                        ]
                    ]
                ],
                [
                    'title' => 'Administración',
                    'title_icon' => 'shield-lock',
                    'requires' => 'admin',
                    'items' => [
                        [
                            'label' => 'Usuarios y Roles',
                            'href' => '../modulos/admin/usuarios.php',
                            'icon' => 'people',
                            'icon_color' => 'dark'
                        ],
                        [
                            'label' => 'Permisos por Módulo',
                            'href' => '../modulos/admin/permisos_roles.php',
                            'icon' => 'shield-lock',
                            'icon_color' => 'danger'
                        ],
                        [
                            'label' => 'Gestionar Módulos',
                            'href' => '../modulos/admin/modulos_admin.php',
                            'icon' => 'grid-3x3-gap',
                            'icon_color' => 'primary'
                        ]
                    ]
                ]
            ]
        ]
    ]
];
