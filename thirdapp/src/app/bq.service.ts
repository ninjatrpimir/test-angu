import { Injectable }       from '@angular/core';
import { HttpClient } from '@angular/common/http';
// import { HttpClientModule } from '@angular/common/http';
import { HttpParams }       from  "@angular/common/http";
// import { HttpClientModule } from '@angular/common/http';
// bit će potrebno importati model klase i RxJS obervable interface
// treba napraviti; import { CloverSelect } from ;
import { Observable } from 'rxjs';

import { CloverModel } from './model-cloverdata';
import { CLOVERDATACONST } from './mock-cloverdata';


@Injectable({
  providedIn: 'root' // registriramo servis u root-u(na taj nacin je dostupna u cijelom app-u)
                     // uvijek provide-amo servis u root injectoru, OSIM ako ne želimo da servis bude dostupan
                     // samo u određenim NgModulima                     
})
export class BqService {

  cloverData: CloverModel[];
  constructor(private httpClient: HttpClient) { }

  // getCloverData(): Observable<CloverData[]> {
  getCloverData(): CloverModel[] {
    // return this.httpClient.get<CloverData[]>('/test-ang/commissions-ang/secondapp/src/app/backend/api/read.php');
    // return this.httpClient.get<CloverData[]>('/backend/api/read.php');
    // return this.httpClient.get<CloverData[]>('https://localhost:444/backend/api/clover_select_default.php');
    // return this.httpClient.get<CloverData[]>('https://others.businessq-software.com/analyticstest/php/clover_select.php');
    // return this.httpClient.subscribe(<CloverData[]>('https://others.businessq-software.com/commissions/php/clover_select.php');
    // return this.httpClient.getTextFile(<CloverData[]>('https://others.businessq-software.com/commissions/php/clover_select.php');
                          // .do(data => console.log(JSON.stringify(data)))
                          // .catch(this.)

    // return this.httpClient.get<CloverData[]>('https://others.businessq-software.com/commissions/php/clover_select.php');
    return CLOVERDATACONST;
  }
}
