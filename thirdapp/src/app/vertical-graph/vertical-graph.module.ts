// Angular Imports
import { NgModule } from '@angular/core';
import { BrowserModule } from '@angular/platform-browser';
import { DxChartModule } from 'devextreme-angular';
import { platformBrowserDynamic } from '@angular/platform-browser-dynamic';
import { VerticalGraphComponent } from './vertical-graph.component';

@NgModule({
    imports: [
        BrowserModule,
        DxChartModule
    ],
    declarations: [VerticalGraphComponent],
    // bootstrap: [VerticalGraphComponent],
    exports: [VerticalGraphComponent, VerticalGraphService]
  })

export class VerticalGraphModule {

}

platformBrowserDynamic().bootstrapModule(VerticalGraphModule);
