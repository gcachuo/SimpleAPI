import {Defaults} from "./defaults";

new Defaults();

import "expose-loader?exposes[]=$!jquery";
import "expose-loader?exposes[]=JSZip!jszip";
import "expose-loader?exposes[]=App!./modules/app";
