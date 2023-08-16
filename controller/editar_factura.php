<?php
/**
 * This file is part of editar_facturas
 * Copyright (C) 2015-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
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
 * Description of editar_factura
 *
 * @author Carlos Garcia Gomez <neorazorx@gmail.com>
 */
class editar_factura extends fbase_controller
{

    public $agente;
    public $cliente_s;
    public $divisa;
    public $fabricante;
    public $factura;
    public $familia;
    public $forma_pago;
    public $impuesto;
    public $nuevo_albaran_url;
    public $pais;
    public $serie;
    public $tesoreria;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Editar factura', 'ventas', TRUE, FALSE);
    }

    protected function private_core()
    {
        $this->agente = new agente();
        $this->divisa = new divisa();
        $this->impuesto = new impuesto();
        $this->fabricante = new fabricante();
        $this->familia = new familia();
        $this->forma_pago = new forma_pago();
        $this->pais = new pais();
        $this->serie = new serie();
        $this->tesoreria = in_array('tesoreria', $GLOBALS['plugins']);

        /**
         * Comprobamos si el usuario tiene acceso a nueva_venta,
         * necesario para poder añadir líneas.
         */
        $this->nuevo_albaran_url = FALSE;
        if ($this->user->have_access_to('nueva_venta', FALSE)) {
            $nuevoalbp = $this->page->get('nueva_venta');
            if ($nuevoalbp) {
                $this->nuevo_albaran_url = $nuevoalbp->url();
            }
        }

        $this->cliente_s = FALSE;
        $this->factura = FALSE;
        if (isset($_REQUEST['id'])) {
            $factura = new factura_cliente();
            $this->factura = $factura->get($_REQUEST['id']);
        }

        if ($this->factura) {
            $cliente = new cliente();
            $this->cliente_s = $cliente->get($this->factura->codcliente);

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
            'name' => 'editar_factura',
            'page_from' => __CLASS__,
            'page_to' => 'ventas_factura',
            'type' => 'button',
            'text' => '<span class="glyphicon glyphicon-edit" aria-hidden="true"></span>'
            . '<span class="hidden-xs">&nbsp; Editar</span>',
            'params' => ''
        );
        $fsext = new fs_extension($extension);
        $fsext->save();
    }

    public function iframe_xid()
    {
        $txt = "<div class='hidden'><iframe src='https://www.facturascripts.com/comm3/index.php?page=community_stats"
            . "&add=TRUE&version=" . $this->version() . "&xid=" . $this->empresa->xid . "&plugins=" . join(',', $GLOBALS['plugins']) . "'>"
            . "</iframe></div>";
        return $txt;
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
            $recibo0 = new recibo_cliente();
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

        /// ¿cambiamos el cliente?
        if ($_POST['cliente'] != $this->factura->codcliente) {
            $this->cliente_s = $this->cliente_s->get($_POST['cliente']);
            if ($this->cliente_s) {
                $this->factura->codcliente = $this->cliente_s->codcliente;
                $this->factura->cifnif = $this->cliente_s->cifnif;
                $this->factura->nombrecliente = $this->cliente_s->razonsocial;
                $this->factura->apartado = NULL;
                $this->factura->ciudad = NULL;
                $this->factura->coddir = NULL;
                $this->factura->codpais = NULL;
                $this->factura->codpostal = NULL;
                $this->factura->direccion = NULL;
                $this->factura->provincia = NULL;

                foreach ($this->cliente_s->get_direcciones() as $d) {
                    if ($d->domfacturacion) {
                        $this->factura->apartado = $d->apartado;
                        $this->factura->ciudad = $d->ciudad;
                        $this->factura->coddir = $d->id;
                        $this->factura->codpais = $d->codpais;
                        $this->factura->codpostal = $d->codpostal;
                        $this->factura->direccion = $d->direccion;
                        $this->factura->provincia = $d->provincia;
                        break;
                    }
                }
            } else {
                $this->factura->codcliente = NULL;
                $this->factura->nombrecliente = $_POST['nombrecliente'];
                $this->factura->cifnif = $_POST['cifnif'];
                $this->factura->coddir = NULL;
            }
        } else {
            $this->factura->nombrecliente = $_POST['nombrecliente'];
            $this->factura->cifnif = $_POST['cifnif'];
            $this->factura->codpais = $_POST['codpais'];
            $this->factura->provincia = $_POST['provincia'];
            $this->factura->ciudad = $_POST['ciudad'];
            $this->factura->codpostal = $_POST['codpostal'];
            $this->factura->direccion = $_POST['direccion'];
        }

        $this->factura->numero2 = $_POST['numero2'];
        $this->factura->observaciones = $_POST['observaciones'];
        $this->factura->set_fecha_hora($_POST['fecha'], $_POST['hora']);
        $this->factura->netosindto = 0;
        $this->factura->neto = 0;
        $this->factura->totaliva = 0;
        $this->factura->totalirpf = 0;
        $this->factura->totalrecargo = 0;
        $this->factura->irpf = 0;
        $this->factura->dtopor1 = floatval($_POST['adtopor1']);
        $this->factura->dtopor2 = floatval($_POST['adtopor2']);
        $this->factura->dtopor3 = floatval($_POST['adtopor3']);
        $this->factura->dtopor4 = floatval($_POST['adtopor4']);
        $this->factura->dtopor5 = floatval($_POST['adtopor5']);

        $this->factura->pagada = isset($_POST['pagada']);
        $this->factura->anulada = isset($_POST['anulada']);

        $this->factura->femail = NULL;
        if (isset($_POST['enviada'])) {
            $this->factura->femail = $_POST['enviada'];
        }

        /// ¿Cambiamos la divisa?
        if ($_POST['divisa'] != $this->factura->coddivisa) {
            $divisa = $this->divisa->get($_POST['divisa']);
            if ($divisa) {
                $this->factura->coddivisa = $divisa->coddivisa;
                $this->factura->tasaconv = $divisa->tasaconv;
            }
        } else if ($_POST['tasaconv'] != '') {
            $this->factura->tasaconv = floatval($_POST['tasaconv']);
        }

        /// ¿Cambiamos la forma de pago?
        if ($_POST['forma_pago'] != $this->factura->codpago) {
            $formap = $this->forma_pago->get($_POST['forma_pago']);
            if ($formap) {
                $this->factura->codpago = $formap->codpago;
                $this->factura->vencimiento = Date('d-m-Y', strtotime($this->factura->fecha . ' ' . $formap->vencimiento));
            }
        } else {
            $this->factura->vencimiento = $_POST['vencimiento'];
        }

        /// ¿Cambiamos la serie?
        if ($_POST['serie'] != $this->factura->codserie) {
            $serie2 = $this->serie->get($_POST['serie']);
            if ($serie2) {
                $this->factura->codserie = $serie2->codserie;
                $this->factura->new_codigo();
            }
        } else if ($_POST['numero'] != $this->factura->numero) {
            $new_codigo = fs_documento_new_codigo(FS_FACTURA, $this->factura->codejercicio, $this->factura->codserie, $_POST['numero']);
            if ($this->factura->get_by_codigo($new_codigo)) {
                $this->new_error_msg("Ya hay una factura con el número " . $_POST['numero']);
            } else {
                $this->factura->numero = $_POST['numero'];
                $this->factura->codigo = $new_codigo;
            }
        }

        /// ¿Cambiamos empleado?
        $this->factura->codagente = NULL;
        $this->factura->porcomision = 0;
        if ($_POST['codagente']) {
            $this->factura->codagente = $_POST['codagente'];
            $this->factura->porcomision = intval($_POST['porcomision']);
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
                        $art0->sum_stock($this->factura->codalmacen, $l->cantidad, FALSE, $l->codcombinacion);
                    }
                } else {
                    $this->new_error_msg("¡Imposible eliminar la línea del artículo " . $l->referencia . "!");
                }
            }
        }

        $regimeniva = 'general';
        if ($this->cliente_s) {
            $regimeniva = $this->cliente_s->regimeniva;
        }

        /// modificamos y/o añadimos las demás líneas
        for ($num = 0; $num <= $numlineas; $num++) {
            $encontrada = FALSE;
              /*            echo '<script>';
                echo 'console.log(' . json_encode($numlineas) . ')';
                echo '</script>';   */
            $referencia_nueva = trim($_POST['referencia_' . $num]);
            if (isset($_POST['idlinea_' . $num])) {
                foreach ($lineas as $k => $value) {
                    /// modificamos la línea
                    if ($value->idlinea == intval($_POST['idlinea_' . $num])) {
                        $encontrada = TRUE;
                        $cantidad_old = $value->cantidad;
                        $lineas[$k]->referencia = $referencia_nueva;
                        $lineas[$k]->cantidad = floatval($_POST['cantidad_' . $num]);
                        $lineas[$k]->pvpunitario = floatval($_POST['pvp_' . $num]);
                        $lineas[$k]->dtopor = floatval(fs_filter_input_post('dto_' . $num, 0));
                        $lineas[$k]->dtopor2 = floatval(fs_filter_input_post('dto2_' . $num, 0));
                        $lineas[$k]->dtopor3 = floatval(fs_filter_input_post('dto3_' . $num, 0));
                        $lineas[$k]->dtopor4 = floatval(fs_filter_input_post('dto4_' . $num, 0));
                        $lineas[$k]->pvpsindto = $value->cantidad * $value->pvpunitario;

                        // Descuento Unificado Equivalente
                        $due_linea = $this->fbase_calc_due(array($lineas[$k]->dtopor, $lineas[$k]->dtopor2, $lineas[$k]->dtopor3, $lineas[$k]->dtopor4));
                        $lineas[$k]->pvptotal = $lineas[$k]->cantidad * $lineas[$k]->pvpunitario * $due_linea;

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
                                $art0 = $articulo->get($referencia_nueva);
                                if ($art0) {
                                    $art0->sum_stock($this->factura->codalmacen, $cantidad_old - $lineas[$k]->cantidad, FALSE, $lineas[$k]->codcombinacion);
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
                    $linea = new linea_factura_cliente();
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
                   
                    $art0 = $articulo->get($_POST['referencia_' . $num]);
                    if ($art0) {
                        $linea->referencia = $art0->referencia;
                        if ($_POST['codcombinacion_' . $num]) {
                            $linea->codcombinacion = $_POST['codcombinacion_' . $num];
                        }
                    }

                    $linea->irpf = floatval(fs_filter_input_post('irpf_' . $num, 0));
                    $linea->cantidad = floatval($_POST['cantidad_' . $num]);
                    $linea->pvpunitario = floatval($_POST['pvp_' . $num]);
                    $linea->dtopor = floatval(fs_filter_input_post('dto_' . $num, 0));
                    $linea->dtopor2 = floatval(fs_filter_input_post('dto2_' . $num, 0));
                    $linea->dtopor3 = floatval(fs_filter_input_post('dto3_' . $num, 0));
                    $linea->dtopor4 = floatval(fs_filter_input_post('dto4_' . $num, 0));
                    $linea->pvpsindto = $linea->cantidad * $linea->pvpunitario;
                    $linea->referencia = $referencia_nueva;
                    // Descuento Unificado Equivalente
                    $due_linea = $this->fbase_calc_due(array($linea->dtopor, $linea->dtopor2, $linea->dtopor3, $linea->dtopor4));
                    $linea->pvptotal = $linea->cantidad * $linea->pvpunitario * $due_linea;

                    if ($linea->save()) {
                        if ($art0) {
                            /// actualizamos el stock
                            $art0->sum_stock($this->factura->codalmacen, 0 - $linea->cantidad, FALSE, $linea->codcombinacion);
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
        $due_totales = $this->fbase_calc_due([$this->factura->dtopor1, $this->factura->dtopor2, $this->factura->dtopor3, $this->factura->dtopor4, $this->factura->dtopor5]);
        foreach ($this->fbase_get_subtotales_documento($this->factura->get_lineas(), $due_totales) as $subt) {
            $this->factura->netosindto += $subt['netosindto'];
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
            $asiento_factura->generar_asiento_venta($this->factura);
        }
    }
}
