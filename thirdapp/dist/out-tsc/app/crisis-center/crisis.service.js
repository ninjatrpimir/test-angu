var __decorate = (this && this.__decorate) || function (decorators, target, key, desc) {
    var c = arguments.length, r = c < 3 ? target : desc === null ? desc = Object.getOwnPropertyDescriptor(target, key) : desc, d;
    if (typeof Reflect === "object" && typeof Reflect.decorate === "function") r = Reflect.decorate(decorators, target, key, desc);
    else for (var i = decorators.length - 1; i >= 0; i--) if (d = decorators[i]) r = (c < 3 ? d(r) : c > 3 ? d(target, key, r) : d(target, key)) || r;
    return c > 3 && r && Object.defineProperty(target, key, r), r;
};
var __metadata = (this && this.__metadata) || function (k, v) {
    if (typeof Reflect === "object" && typeof Reflect.metadata === "function") return Reflect.metadata(k, v);
};
import { BehaviorSubject } from 'rxjs';
import { map } from 'rxjs/operators';
import { Injectable } from '@angular/core';
import { MessageService } from '../message.service';
import { CRISES } from './mock-crises';
var CrisisService = /** @class */ (function () {
    function CrisisService(messageService) {
        this.messageService = messageService;
        this.crises$ = new BehaviorSubject(CRISES);
    }
    CrisisService_1 = CrisisService;
    CrisisService.prototype.getCrises = function () { return this.crises$; };
    CrisisService.prototype.getCrisis = function (id) {
        return this.getCrises().pipe(map(function (crises) { return crises.find(function (crisis) { return crisis.id === +id; }); }));
    };
    CrisisService.prototype.addCrisis = function (name) {
        name = name.trim();
        if (name) {
            var crisis = { id: CrisisService_1.nextCrisisId++, name: name };
            CRISES.push(crisis);
            this.crises$.next(CRISES);
        }
    };
    var CrisisService_1;
    CrisisService.nextCrisisId = 100;
    CrisisService = CrisisService_1 = __decorate([
        Injectable({
            providedIn: 'root',
        }),
        __metadata("design:paramtypes", [MessageService])
    ], CrisisService);
    return CrisisService;
}());
export { CrisisService };
/*
Copyright Google LLC. All Rights Reserved.
Use of this source code is governed by an MIT-style license that
can be found in the LICENSE file at http://angular.io/license
*/ 
//# sourceMappingURL=crisis.service.js.map