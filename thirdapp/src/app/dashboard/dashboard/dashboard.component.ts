// TODO: Feature Componetized like CrisisCenter
import { Observable } from 'rxjs';
import { switchMap } from 'rxjs/operators';
import { Component, OnInit } from '@angular/core';
import { ActivatedRoute } from '@angular/router';

// import { DashboardService }  from '../dashboard.service';
// import { Commission } from '../commission'; nepotrebna klasa?
import { BqService } from '../../bq.service';
import { CloverModel } from '../../model-cloverdata';






@Component({
    moduleId: module.id,
    selector: 'dashboard',
    templateUrl: 'dashboard.component.html',
    styleUrls: ['dashboard.component.scss']
})
export class DashboardComponent implements OnInit {
    // commissions$: Observable<Commission[]>;
    // selectedId: number;

    // constructor(
    //     private service: DashboardService,
    //     private route: ActivatedRoute
    // ) {}
    cloverData: CloverModel[] = [];
    error:      '';
    

    constructor(private bqService: BqService) {}  

  ngOnInit() {
    // this.cloverData = this.BqService;
    this.getCloverData();
    
    // this.commissions$ = this.route.paramMap.pipe(
      // switchMap(params => {
        // (+) before `params.get()` turns the string into a number
        // this.selectedId = +params.get('id');
        // return this.service.getCommissions();
      // })
    // );
  }

  getCloverData(): void {
    // var that = this;

    // this.bqService.getCloverData()
    // .subscribe(
      // res => this.cloverData = res,
      // err => this.error = err
      // data => console.log(JSON.stringify(this.cloverData))
    // );

    this.cloverData = this.bqService.getCloverData();

    // console.log(this.cloverData, 'cloverData');

    // this.bqService.getCloverData().subscribe(
    //   (res: CloverData[]) => {
    //     this.cloverData = res;
    //   },
    //   (err) => {
    //     this.error = err;
    //   }
    // );
  }
}
