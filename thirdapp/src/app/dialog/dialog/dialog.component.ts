import { Component } from '@angular/core';
// import {DialogService} from '../dialog/dialog.service';
import {DialogService} from './dialog.service';

@Component({
    selector: 'dialog-page',
    // templateUrl: './dialog.service',
    templateUrl: './dialog.component.html',
})

export class DialogComponent
{
    constructor(public dialogService:DialogService){}

    openModal() 
    {
        var data = null;
        this.dialogService.openModal('Naslov2', 'Poruka poslana', ()=> {
            console.log('Yes');

        }, ()=> {
            console.log('No');
        });



            // const dialogConfig = new MatDialogConfig();

            // dialogConfig.disableClose = true;
            // dialogConfig.autoFocus = true;
            // dialogConfig.data = {
            //     id: 1,
            //     title: 'Angular For Beginners'
            // };

            // const dialogRef = this.dialog.open(DialogTemplateComponent, dialogConfig);

            // dialogRef.afterClosed().subscribe(result => {
            //   console.log(result);
            // });


    }
}