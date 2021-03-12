import {Defaults} from "./defaults";

$(() => {
    Defaults.init();
});

import "expose-loader?exposes[]=$&exposes[]=jQuery!jquery";
import "expose-loader?exposes[]=JSZip!jszip";
import "expose-loader?exposes[]=App!./modules/app";
