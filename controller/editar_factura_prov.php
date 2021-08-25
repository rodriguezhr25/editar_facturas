<?php
/**
 * This file is part of editar_facturas
 * Copyright (C) 2015-2019 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
require_once 'plugins/facturacion_base/extras/fbase_controller.php';

/**
 * Description of editar_factura_prov
 *
 * @author Carlos Garcia Gomez <neorazorx@gmail.com>
 */
class editar_factura_prov extends fbase_controller
{

    public $agente;
    public $divisa;
    public $factura;
    public $fabricante;
    public $familia;
    public $forma_pago;
    public $impuesto;
    public $nuevo_albaran_url;
    public $proveedor_s;
    public $serie;
    public $tesoreria;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'editar factura', 'compras', FALSE, FALSE);
    }

    protected function private_core()
    {
        $this->agente = new agente();
        $this->divisa = new divisa();
        $this->fabricante = new fabricante();
        $this->familia = new familia();
        $this->forma_pago = new forma_pago();
        $this->impuesto = new impuesto();
        $this->serie = new serie();
        $this->tesoreria = in_array('tesoreria', $GLOBALS['plugins']);

        /// comprobamos si el usuario tiene acceso a nueva_compra
        $this->nuevo_albaran_url = FALSE;
        if ($this->user->have_access_to('nueva_compra', FALSE)) {
            $nuevoalbp = $this->page->get('nueva_compra');
            if ($nuevoalbp) {
                $this->nuevo_albaran_url = $nuevoalbp->url();
            }
        }

        $this->factura = FALSE;
        if (isset($_REQUEST['id'])) {
            $fact0 = new factura_proveedor();
            $this->factura = $fact0->get($_REQUEST['id']);
        }

        if ($this->factura) {
            $proveedor = new proveedor();
            $this->proveedor_s = $proveedor->get($this->factura->codproveedor);

            if (isset($_POST['numlineas']) && $this->factura_editable()) {
                $this->modificar_factura();
            }
        } else {
            $this->new_error_msg('Factura no encontrada.');
        }

        $this->share_extensions();
    }

    public function url()
    {
        if (!isset($this->factura)) {
            return parent::url();
        } else if ($this->factura) {
            return $this->page->url() . '&id=' . $this->factura->idfactura;
        }

        return $this->page->url();
    }

    private function share_extensions()
    {
        $extension = array(
            'name' => 'editar_facturap',
            'page_from' => __CLASS__,
            'page_to' => 'compras_factura',
            'type' => 'button',
            'text' => '<span class="glyphicon glyphicon-edit" aria-hidden="true"></span>'
            . '<span class="hidden-xs">&nbsp; Editar</span>',
            'params' => ''
        );
        $fsext = new fs_extension($extension);
        $fsext->save();
    }

    private function factura_editable()
    {
        $editable = TRUE;

        $eje0 = new ejercicio();
        $ejercicio = $eje0->get($this->factura->codejercicio);
        if ($ejercicio) {
            if ($ejercicio->abierto()) {
                $regiva = new regularizacion_iva();
                if ($regiva->get_fecha_inside($this->factura->fecha)) {
                    $this->new_error_msg('Ya hay una regularización de ' . FS_IVA . ' sobre este periodo.'
                        . ' No se puede modificar esta factura.');
                    $editable = FALSE;
                }
            } else {
                $this->new_error_msg('El ejercicio ' . $ejercicio->nombre . ' está cerrado.'
                    . ' No se puede modificar esta factura.');
                $editable = FALSE;
            }
        }

        return $editable;
    }

    private function modificar_factura()
    {
        $asient0 = new asiento();
        $articulo = new articulo();

        /// paso 1, eliminamos los asientos asociados
        if (!is_null($this->factura->idasiento)) {
            $asiento = $asient0->get($this->factura->idasiento);
            if ($asiento) {
                if ($asiento->delete()) {
                    $this->factura->idasiento = NULL;
                }
            } else {
                $this->factura->idasiento = NULL;
            }
        }
        /// asiento de pago
        if (!is_null($this->factura->idasientop)) {
            $asiento = $asient0->get($this->factura->idasientop);
            if ($asiento) {
                if ($asiento->delete()) {
                    $this->factura->idasientop = NULL;
                }
            } else {
                $this->factura->idasientop = NULL;
            }
        }

        /// paso 2, eliminar las líneas de IVA
        foreach ($this->factura->get_lineas_iva() as $liva) {
            $liva->delete();
        }

        /// paso 3, eliminar los recibos asociados
        if ($this->tesoreria) {
            $borrar = TRUE;
            $recibo0 = new recibo_proveedor();
            foreach ($recibo0->all_from_factura($this->factura->idfactura) as $rec) {
                if ($rec->estado == 'Pagado') {
                    $borrar = FALSE;
                    break;
                }
            }

            if ($borrar) {
                foreach ($recibo0->all_from_factura($this->factura->idfactura) as $rec) {
                    $rec->delete();
                }
            } else {
                $this->new_error_msg('Ya hay recibos pagados. No se puede modificar la factura.');
                return FALSE;
            }
        }

        /// ¿Cambiamos el proveedor?
        if ($_POST['proveedor'] != $this->factura->codproveedor) {
            $this->proveedor_s = $this->proveedor_s->get($_POST['proveedor']);
            if ($this->proveedor_s) {
                $this->factura->codproveedor = $this->proveedor_s->codproveedor;
                $this->factura->nombre = $this->proveedor_s->razonsocial;
                $this->factura->cifnif = $this->proveedor_s->cifnif;
            } else {
                $this->factura->codproveedor = NULL;
                $this->factura->nombre = $_POST['nombre'];
                $this->factura->cifnif = $_POST['cifnif'];
            }
        } else {
            $this->factura->nombre = $_POST['nombre'];
            $this->factura->cifnif = $_POST['cifnif'];
        }

        $this->factura->numproveedor = $_POST['numproveedor'];
        $this->factura->observaciones = $_POST['observaciones'];
        $this->factura->set_fecha_hora($_POST['fecha'], $_POST['hora']);
        $this->factura->neto = 0;
        $this->factura->totaliva = 0;
        $this->factura->totalirpf = 0;
        $this->factura->totalrecargo = 0;
        $this->factura->irpf = 0;

        $this->factura->pagada = isset($_POST['pagada']);
        $this->factura->anulada = isset($_POST['anulada']);

        /// ¿Cambiamos la divisa?
        if ($_POST['divisa'] != $this->factura->coddivisa) {
            $divisa = $this->divisa->get($_POST['divisa']);
            if ($divisa) {
                $this->factura->coddivisa = $divisa->coddivisa;
                $this->factura->tasaconv = $divisa->tasaconv_compra;
            }
        } else if ($_POST['tasaconv'] != '') {
            $this->factura->tasaconv = floatval($_POST['tasaconv']);
        }

        /// ¿Cambiamos la forma de pago?
        if ($_POST['forma_pago'] != $this->factura->codpago) {
            $formap = $this->forma_pago->get($_POST['forma_pago']);
            if ($formap) {
                $this->factura->codpago = $formap->codpago;
            }
        }

        /// ¿Cambiamos la serie?
        if ($_POST['serie'] != $this->factura->codserie) {
            $serie2 = $this->serie->get($_POST['serie']);
            if ($serie2) {
                $this->factura->codserie = $serie2->codserie;
                $this->factura->new_codigo();
            }
        }

        /// ¿Cambiamos empleado?
        $this->factura->codagente = NULL;
        if ($_POST['codagente']) {
            $this->factura->codagente = $_POST['codagente'];
        }

        /// eliminamos las líneas que no encontremos en el $_POST
        $serie = $this->serie->get($this->factura->codserie);
        $numlineas = intval($_POST['numlineas']);
        $lineas = $this->factura->get_lineas();
        foreach ($lineas as $l) {
            $encontrada = FALSE;
            for ($num = 0; $num <= $numlineas; $num++) {
                if (isset($_POST['idlinea_' . $num]) && $l->idlinea == intval($_POST['idlinea_' . $num])) {
                    $encontrada = TRUE;
                    break;
                }
            }
            if (!$encontrada) {
                if ($l->delete()) {
                    /// actualizamos el stock
                    $art0 = $articulo->get($l->referencia);
                    if ($art0) {
                        $art0->sum_stock($this->factura->codalmacen, 0 - $l->cantidad, TRUE, $l->codcombinacion);
                    }
                } else {
                    $this->new_error_msg("¡Imposible eliminar la línea del artículo " . $l->referencia . "!");
                }
            }
        }

        $regimeniva = 'general';
        if ($this->proveedor_s) {
            $regimeniva = $this->proveedor_s->regimeniva;
        }

        /// modificamos y/o añadimos las demás líneas
        for ($num = 0; $num <= $numlineas; $num++) {
            $encontrada = FALSE;
            if (isset($_POST['idlinea_' . $num])) {
                foreach ($lineas as $k => $value) {
                    /// modificamos la línea
                    if ($value->idlinea == intval($_POST['idlinea_' . $num])) {
                        $encontrada = TRUE;
                        $cantidad_old = $value->cantidad;
                        $lineas[$k]->cantidad = floatval($_POST['cantidad_' . $num]);
                        $lineas[$k]->pvpunitario = floatval($_POST['pvp_' . $num]);
                        $lineas[$k]->dtopor = floatval(fs_filter_input_post('dto_' . $num, 0));
                        $lineas[$k]->pvpsindto = $value->cantidad * $value->pvpunitario;
                        $lineas[$k]->pvptotal = $value->cantidad * $value->pvpunitario * (100 - $value->dtopor) / 100;
                        $lineas[$k]->descripcion = $_POST['desc_' . $num];

                        $lineas[$k]->codimpuesto = NULL;
                        $lineas[$k]->iva = 0;
                        $lineas[$k]->recargo = 0;
                        $lineas[$k]->irpf = floatval(fs_filter_input_post('irpf_' . $num, 0));
                        if (!$serie->siniva && $regimeniva != 'Exento') {
                            $imp0 = $this->impuesto->get_by_iva($_POST['iva_' . $num]);
                            if ($imp0) {
                                $lineas[$k]->codimpuesto = $imp0->codimpuesto;
                            }

                            $lineas[$k]->iva = floatval($_POST['iva_' . $num]);
                            $lineas[$k]->recargo = floatval(fs_filter_input_post('recargo_' . $num, 0));
                        }

                        if ($lineas[$k]->save()) {
                            if ($value->irpf > $this->factura->irpf) {
                                $this->factura->irpf = $value->irpf;
                            }

                            if ($lineas[$k]->cantidad != $cantidad_old) {
                                /// actualizamos el stock
                                $art0 = $articulo->get($value->referencia);
                                if ($art0) {
                                    $art0->sum_stock($this->factura->codalmacen, $lineas[$k]->cantidad - $cantidad_old, TRUE, $lineas[$k]->codcombinacion);
                                }
                            }
                        } else {
                            $this->new_error_msg("¡Imposible modificar la línea del artículo " . $value->referencia . "!");
                        }
                        break;
                    }
                }

                /// añadimos la línea
                if (!$encontrada && intval($_POST['idlinea_' . $num]) == -1 && isset($_POST['referencia_' . $num])) {
                    $linea = new linea_factura_proveedor();
                    $linea->idfactura = $this->factura->idfactura;
                    $linea->descripcion = $_POST['desc_' . $num];

                    if (!$serie->siniva && $regimeniva != 'Exento') {
                        $imp0 = $this->impuesto->get_by_iva($_POST['iva_' . $num]);
                        if ($imp0) {
                            $linea->codimpuesto = $imp0->codimpuesto;
                        }

                        $linea->iva = floatval($_POST['iva_' . $num]);
                        $linea->recargo = floatval(fs_filter_input_post('recargo_' . $num, 0));
                    }

                    $linea->irpf = floatval(fs_filter_input_post('irpf_' . $num, 0));
                    $linea->cantidad = floatval($_POST['cantidad_' . $num]);
                    $linea->pvpunitario = floatval($_POST['pvp_' . $num]);
                    $linea->dtopor = floatval(fs_filter_input_post('dto_' . $num, 0));
                    $linea->pvpsindto = $linea->cantidad * $linea->pvpunitario;
                    $linea->pvptotal = $linea->cantidad * $linea->pvpunitario * (100 - $linea->dtopor) / 100;

                    $art0 = $articulo->get($_POST['referencia_' . $num]);
                    if ($art0) {
                        $linea->referencia = $art0->referencia;
                        if ($_POST['codcombinacion_' . $num]) {
                            $linea->codcombinacion = $_POST['codcombinacion_' . $num];
                        }
                    }

                    if ($linea->save()) {
                        if ($art0) {
                            /// actualizamos el stock
                            $art0->sum_stock($this->factura->codalmacen, $linea->cantidad, TRUE, $linea->codcombinacion);
                        }

                        if ($linea->irpf > $this->factura->irpf) {
                            $this->factura->irpf = $linea->irpf;
                        }
                    } else {
                        $this->new_error_msg("¡Imposible guardar la línea del artículo " . $linea->referencia . "!");
                    }
                }
            }
        }

        /// obtenemos los subtotales por impuesto
        foreach ($this->fbase_get_subtotales_documento($this->factura->get_lineas()) as $subt) {
            $this->factura->neto += $subt['neto'];
            $this->factura->totaliva += $subt['iva'];
            $this->factura->totalirpf += $subt['irpf'];
            $this->factura->totalrecargo += $subt['recargo'];
        }

        $this->factura->total = round($this->factura->neto + $this->factura->totaliva - $this->factura->totalirpf + $this->factura->totalrecargo, FS_NF0);

        if (abs(floatval($_POST['atotal']) - $this->factura->total) > .01) {
            $this->new_error_msg("El total difiere entre el controlador y la vista (" . $this->factura->total .
                " frente a " . $_POST['atotal'] . "). Debes informar del error.");
        } else if ($this->factura->save()) {
            $this->new_message('Factura modificada correctamente.');
            $this->generar_asiento();
        } else {
            $this->new_error_msg('Imposible modificar la factura.');
        }
    }

    private function generar_asiento()
    {
        if ($this->factura->get_asiento()) {
            $this->new_error_msg('Ya hay un asiento asociado a esta factura.');
        } else {
            $asiento_factura = new asiento_factura();
            $asiento_factura->soloasiento = TRUE;
            $asiento_factura->generar_asiento_compra($this->factura);
        }
    }
}
