import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { slideInAnimation } from './animations';

import {DialogService} from './dialog/dialog/dialog.service';
import {DialogComponent} from './dialog/dialog/dialog.component';

@Component({
  selector: 'app-root',
  templateUrl: 'app.component.html',
  styleUrls: ['app.component.css'],
  animations: [ slideInAnimation ]
})
export class AppComponent 
{
  constructor(public dialogService: DialogService) {}
  // constructor(public dialogService: DialogService, public dialogComponent: DialogComponent) {}
  // constructor(public dialogComponent: DialogComponent) {}

  getAnimationData(outlet: RouterOutlet) {
    return outlet && outlet.activatedRouteData && outlet.activatedRouteData['animation'];
  }

  public openModal()
  {
    // this.dialogComponent.dialogService.openModal('Naslov2', 'Poruka poslana',()=> {
    this.dialogService.openModal('Naslov2', 'Poruka poslana',()=> {
      console.log('Yes');
    }, ()=> {
      console.log('No.');
    });
    // this.dialogComponent.openModal();
  }

  // openModal()
  // {
    // this.dialogComponent.dialogService.openModal('Naslov2', 'Poruka poslana',()=> {
    //   console.log('Yes');
    // }, ()=> {
    //   console.log('No.');
    // });
    // this.dialogComponent.openModal();
  // }

  // openModal() 
  //   {
  //       var data = null;
  //       this.dialogService.openModal('Naslov2', 'Poruka poslana', ()=> {
  //           console.log('Yes');

  //       }, ()=> {
  //           console.log('No');
  //       })

  //           // const dialogConfig = new MatDialogConfig();

  //           // dialogConfig.disableClose = true;
  //           // dialogConfig.autoFocus = true;
  //           // dialogConfig.data = {
  //           //     id: 1,
  //           //     title: 'Angular For Beginners'
  //           // };

  //           // const dialogRef = this.dialog.open(DialogTemplateComponent, dialogConfig);

  //           // dialogRef.afterClosed().subscribe(result => {
  //           //   console.log(result);
  //           // });
  //   }

}


/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/