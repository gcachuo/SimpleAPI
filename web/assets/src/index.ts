import {Defaults} from "./defaults";

new Defaults();

import "expose-loader?exposes[]=$!jquery";
import "expose-loader?exposes[]=App!./modules/app";
