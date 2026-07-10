const funcs = require('../functions/functions')
const compiledTemplates = require('../compiledTemplates/compiledTemplates')
const socketEvents = require('../events/events')
const { Sequelize, Op, DataTypes } = require("sequelize");
const striptags = require('striptags');
const moment = require("moment")

const DisconnectController = async (ctx, reason, io,socket) => {
  console.log('a user disconnected ' + socket.id + " " + reason);
  let hash = ctx.socketIdUserHash[socket.id]
  let user_id = ctx.userHashUserId[hash]
  ctx.userIdCount[user_id] > 0 ? ctx.userIdCount[user_id] = ctx.userIdCount[user_id] - 1 : delete ctx.userIdCount[user_id]
  if (ctx.userIdCount[user_id] === 0) {
      delete ctx.userIdCount[user_id]
      delete ctx.userHashUserId[hash]

      // emit user logged off
      let followers = await ctx.wo_followers.findAll({
          attributes: ["following_id"],
          where: {
              follower_id: user_id,
              following_id: {
                  [Op.not]: user_id
              }
          },
          raw: true
      })

      for (let follow of followers) {
          await io.to(follow.following_id).emit("on_user_loggedoff", { user_id: user_id })
      }
  }
  if (ctx.userIdSocket[user_id]) {
      ctx.userIdSocket[user_id] = ctx.userIdSocket[user_id].filter(d => d.id != socket.id)
  }
  ctx.userIdExtra = {}
  delete ctx.socketIdUserHash[socket.id]
};

module.exports = { DisconnectController };