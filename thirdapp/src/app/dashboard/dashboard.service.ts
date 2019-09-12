import { Injectable } from '@angular/core';

import { Observable, of } from 'rxjs';
import { map } from 'rxjs/operators';

// import { Commission } from './commission';
// import { COMMISSIONS } from './mock-commission';
import { MessageService } from '../message.service';

@Injectable({
  providedIn: 'root',
})
export class DashboardService {

  constructor(private messageService: MessageService) { }


  // getCommissions(): Observable<Cloverdatamockdata[]> {
    // TODO: send the message _after_ fetching the commissions
    // this.messageService.add('CommissionService: fetched commissions');
    // return of(COMMISSIONS);
  // }

  // getCommission(id: number | string) {
    // return this.getCommissions();
    // return this.getCommissions().pipe(
      // (+) before `id` turns the string into a number
      // map((commissions: Commission[]) => commissions.find(commission => commission.id === +id))
    // );
  // }
}



/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/