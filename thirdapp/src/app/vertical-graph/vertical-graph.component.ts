import { Component, enableProdMode } from '@angular/core';
import { VerticalGraphService, ComplaintsWithPercent } from './vertical-graph.service';
import { VerticalGraphComponent } from './vertical-graph.component';

// if(!/localhost/.test(document.location.host)) 
// {
//   enableProdMode();
// }

@Component({
    selector: 'vertical-graph',
    providers: [VerticalGraphService],
    templateUrl: 'vertical-graph.component.html',
    styleUrls: ['vertical-graph.component.scss']
})

export class VerticalGraphComponent 
{

  dataSource: ComplaintsWithPercent[];
  
  constructor(service: VerticalGraphService)
  {
    this.dataSource = service.getComplaintsData();
  }

  customizeLabelText = (info: any) => {
    return info.valueText + "%";
  }
}

@NgModule({
  imports: [
      BrowserModule,
      DxChartModule
  ],
  declarations: [VerticalGraphComponent],
  // bootstrap: [VerticalGraphComponent],
  exports: [VerticalGraphComponent]
})




